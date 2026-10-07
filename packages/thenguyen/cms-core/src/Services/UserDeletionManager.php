<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Exceptions\UserDeletionVetoException;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionContext;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionResult;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * The single Core-owned authority for deleting user accounts
 * (CORE-USER-LIFECYCLE-1, v1.0.0-beta.7.1.31).
 *
 * Every Core-supported user-deletion path routes through {@see delete()} (or
 * {@see deleteMany()}), which runs a synchronous, typed, transaction-scoped
 * PRE-DELETE lifecycle. A registered handler may abort the deletion with a typed
 * {@see UserDeletionVeto} BEFORE the row — and its FK cascade — is removed.
 *
 * This is NOT the fire-and-forget {@see HookManager} action system: a veto is
 * honoured (deletion is cancelled and rolled back), and an unexpected handler
 * exception fails CLOSED (rollback + re-throw) — it never allows the deletion to
 * proceed and is never silently swallowed.
 *
 * Transaction boundary (documented, proven in tests):
 *   BEGIN
 *     reload + lock the target row (lockForUpdate)
 *     run pre-delete handlers
 *       veto                -> ROLLBACK, return a vetoed result (no mutation)
 *       unexpected throwable -> ROLLBACK, re-throw (fail closed)
 *     delete() through the supported Eloquent path  (fires FK cascades)
 *   COMMIT
 *
 * Core owns the application-level transaction only. Handlers that coordinate
 * plugin-domain invariants across several operations must acquire their OWN
 * domain locks in a consistent order; Core never locks plugin tables. Raw SQL /
 * query-builder deletion performed OUTSIDE this service is beyond this contract.
 *
 * Authorization boundary: this service enforces the EXTENSION lifecycle only. It
 * is not an authorization gate and must only be reached AFTER the caller has
 * authorized the deletion (the Filament UserResource `canDelete()` policy guards
 * the admin path). It is never an authorization bypass.
 */
class UserDeletionManager
{
    /**
     * @var array<int, array{callback: callable, priority: int, seq: int, meta: array<string, mixed>}>
     */
    private array $handlers = [];

    /** Monotonic counter giving stable ordering for equal priorities. */
    private int $sequence = 0;

    /**
     * Register a pre-delete handler. It receives a {@see UserDeletionContext} and
     * either returns a {@see UserDeletionVeto} (or throws
     * {@see UserDeletionVetoException}) to veto, or returns null/void to allow.
     * Handlers run in ascending priority order, ties broken by registration
     * order. The $meta is safe discovery metadata (source, label) only.
     *
     * @param  callable(UserDeletionContext): (UserDeletionVeto|null)  $handler
     * @param  array<string, mixed>  $meta
     */
    public function registerPreDelete(callable $handler, int $priority = 10, array $meta = []): void
    {
        $this->handlers[] = [
            'callback' => $handler,
            'priority' => $priority,
            'seq' => $this->sequence++,
            'meta' => $this->normalizeMeta($meta),
        ];
    }

    public function hasPreDeleteHandlers(): bool
    {
        return $this->handlers !== [];
    }

    /**
     * Number of registered pre-delete handlers. Discovery / health only — never
     * exposes the callbacks themselves.
     */
    public function preDeleteHandlerCount(): int
    {
        return count($this->handlers);
    }

    /**
     * Delete a single user account through the vetoable lifecycle.
     *
     * @param  Model  $user  the user model to delete
     */
    public function delete(Model $user, ?Authenticatable $actor = null, string $source = UserDeletionContext::SOURCE_UNKNOWN): UserDeletionResult
    {
        DB::beginTransaction();

        try {
            $fresh = $this->lockTarget($user);

            if ($fresh === null) {
                // Target vanished before we could act — idempotent no-op.
                DB::commit();

                return UserDeletionResult::absent();
            }

            $veto = $this->runHandlers(new UserDeletionContext(
                userId: (int) $fresh->getKey(),
                actor: $actor,
                source: $source,
            ));

            if ($veto instanceof UserDeletionVeto) {
                // Expected business veto: abort before any destructive mutation.
                DB::rollBack();

                return UserDeletionResult::vetoed($veto);
            }

            $deleted = (bool) $fresh->delete();

            DB::commit();

            return UserDeletionResult::deleted($deleted);
        } catch (\Throwable $e) {
            // Unexpected failure (including a handler throwing anything other than
            // UserDeletionVetoException, which is handled inside runHandlers):
            // fail closed — roll back and re-throw. Never continue the deletion.
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Delete several accounts ATOMICALLY: if ANY target vetoes, NOTHING is
     * deleted. Preflight (handlers) and mutation share one transaction and lock
     * targets in deterministic ascending-key order to reduce deadlock risk.
     *
     * There is no Core bulk-delete UI today; this exists so a future supported
     * bulk path routes through the same authority rather than bypassing it.
     *
     * @param  array<int, Model>  $users
     * @return array<int, UserDeletionResult>  keyed by user id
     */
    public function deleteMany(array $users, ?Authenticatable $actor = null, string $source = UserDeletionContext::SOURCE_UNKNOWN): array
    {
        $ordered = $this->orderByKeyAscending($users);

        if ($ordered === []) {
            return [];
        }

        DB::beginTransaction();

        try {
            /** @var array<int, UserDeletionResult> $results */
            $results = [];
            /** @var array<int, Model> $locked */
            $locked = [];
            $vetoed = false;

            // Phase 1 — lock + run handlers for every target (no mutation yet).
            foreach ($ordered as $user) {
                $fresh = $this->lockTarget($user);

                if ($fresh === null) {
                    $results[(int) $user->getKey()] = UserDeletionResult::absent();

                    continue;
                }

                $id = (int) $fresh->getKey();
                $veto = $this->runHandlers(new UserDeletionContext($id, $actor, $source));

                if ($veto instanceof UserDeletionVeto) {
                    $results[$id] = UserDeletionResult::vetoed($veto);
                    $vetoed = true;

                    continue;
                }

                $locked[$id] = $fresh;
                $results[$id] = UserDeletionResult::deleted(false); // provisional
            }

            if ($vetoed) {
                // Atomic: a single veto aborts the whole batch, nothing deleted.
                DB::rollBack();

                return $results;
            }

            // Phase 2 — all allowed: delete in the same deterministic order.
            foreach ($locked as $id => $fresh) {
                $results[$id] = UserDeletionResult::deleted((bool) $fresh->delete());
            }

            DB::commit();

            return $results;
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Run handlers in deterministic order. A handler that returns a veto OR
     * throws {@see UserDeletionVetoException} stops the run and yields that veto.
     * Any other throwable is UNEXPECTED and propagates (the caller rolls back and
     * fails closed) — it is never turned into a veto.
     */
    private function runHandlers(UserDeletionContext $context): ?UserDeletionVeto
    {
        foreach ($this->sortedHandlers() as $entry) {
            try {
                $result = ($entry['callback'])($context);
            } catch (UserDeletionVetoException $e) {
                return $e->veto();
            }

            if ($result instanceof UserDeletionVeto) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Reload + lock the target inside the active transaction so the lifecycle
     * decision is made against the committed row. lockForUpdate is a real row
     * lock on MySQL and a harmless no-op on SQLite.
     */
    private function lockTarget(Model $user): ?Model
    {
        $key = $user->getKey();

        if ($key === null) {
            return null;
        }

        return $user->newQueryWithoutScopes()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<int, array{callback: callable, priority: int, seq: int, meta: array<string, mixed>}>
     */
    private function sortedHandlers(): array
    {
        $entries = $this->handlers;

        usort($entries, static function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority'] ?: $a['seq'] <=> $b['seq'];
        });

        return $entries;
    }

    /**
     * Unique targets ordered by ascending primary key. A null key is dropped (a
     * not-yet-persisted model cannot be deleted).
     *
     * @param  array<int, Model>  $users
     * @return array<int, Model>
     */
    private function orderByKeyAscending(array $users): array
    {
        $byKey = [];

        foreach ($users as $user) {
            $key = $user->getKey();

            if ($key === null) {
                continue;
            }

            $byKey[(int) $key] = $user;
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * Keep only known, safe discovery metadata keys.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $out = [];

        foreach (['source', 'source_slug', 'label'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && $meta[$key] !== '') {
                $out[$key] = $meta[$key];
            }
        }

        return $out;
    }
}
