<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\TrustedScriptApproval;
use TheNguyen\CMS\Models\TrustedScriptApprovalEvent;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptDeclaration;
use Throwable;

/**
 * Database authority for trusted external script approvals
 * (CORE-EXTENSION-TRUST S1, ADR D-2).
 *
 * Approval is bound to the declaration fingerprint: a declaration is approved
 * only when its row is `approved` AND the stored fingerprint equals the current
 * one. Any manifest change therefore drops the script back to "awaiting
 * approval" without anyone touching the database. Plugin deactivation revokes
 * every approval the plugin holds, so reactivation always needs a fresh,
 * explicit approval.
 *
 * Reads fail closed (unreadable table => nothing approved). Every write runs in
 * a transaction together with its audit event. The read snapshot is memoised on
 * the current request only, never on this (singleton) object.
 */
class TrustedScriptApprovalStore
{
    public const STATE_APPROVED = 'approved';

    public const STATE_PENDING = 'pending';

    public const STATE_CHANGED = 'changed';

    public const STATE_REVOKED = 'revoked';

    public const REASON_ADMIN = 'admin';

    public const REASON_PLUGIN_DEACTIVATED = 'plugin_deactivated';

    private const REQUEST_KEY = 'cms.trusted_scripts.approvals';

    /** Whether the declaration's CURRENT fingerprint is approved. */
    public function isApproved(TrustedScriptDeclaration $declaration): bool
    {
        $row = $this->rows()[$this->id($declaration->owner, $declaration->key)] ?? null;

        return $row !== null
            && $row['status'] === TrustedScriptApproval::STATUS_APPROVED
            && hash_equals($row['fingerprint'], $declaration->fingerprint);
    }

    /** approved | pending (never approved) | changed (approved an older fingerprint) | revoked. */
    public function state(TrustedScriptDeclaration $declaration): string
    {
        $row = $this->rows()[$this->id($declaration->owner, $declaration->key)] ?? null;

        if ($row === null) {
            return self::STATE_PENDING;
        }

        if ($row['status'] !== TrustedScriptApproval::STATUS_APPROVED) {
            return self::STATE_REVOKED;
        }

        return hash_equals($row['fingerprint'], $declaration->fingerprint)
            ? self::STATE_APPROVED
            : self::STATE_CHANGED;
    }

    /** The stored approval row for display, or null. */
    public function record(string $owner, string $key): ?TrustedScriptApproval
    {
        try {
            return TrustedScriptApproval::query()
                ->where('owner_slug', $owner)
                ->where('script_key', $key)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** Approve exactly this declaration (its fingerprint). */
    public function approve(TrustedScriptDeclaration $declaration, ?int $actorId): void
    {
        DB::transaction(function () use ($declaration, $actorId): void {
            TrustedScriptApproval::query()->updateOrCreate(
                ['owner_slug' => $declaration->owner, 'script_key' => $declaration->key],
                [
                    'fingerprint' => $declaration->fingerprint,
                    'url' => $declaration->url,
                    'status' => TrustedScriptApproval::STATUS_APPROVED,
                    'approved_by' => $actorId,
                    'approved_at' => now(),
                    'revoked_by' => null,
                    'revoked_at' => null,
                    'revocation_reason' => null,
                ],
            );

            $this->event($declaration->owner, $declaration->key, $declaration->fingerprint, 'approved', $actorId);
        });

        $this->forget();
    }

    /** Revoke a plugin script's approval. Returns false when nothing was in force. */
    public function revoke(string $owner, string $key, ?int $actorId, string $reason = self::REASON_ADMIN): bool
    {
        $revoked = DB::transaction(function () use ($owner, $key, $actorId, $reason): bool {
            $row = TrustedScriptApproval::query()
                ->where('owner_slug', $owner)
                ->where('script_key', $key)
                ->where('status', TrustedScriptApproval::STATUS_APPROVED)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $row->forceFill([
                'status' => TrustedScriptApproval::STATUS_REVOKED,
                'revoked_by' => $actorId,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $action = $reason === self::REASON_PLUGIN_DEACTIVATED ? 'revoked_plugin_deactivated' : 'revoked';
            $this->event($owner, $key, $row->fingerprint, $action, $actorId);

            return true;
        });

        $this->forget();

        return $revoked;
    }

    /** Revoke every approval a plugin holds (plugin deactivation). Returns the count. */
    public function revokeAllForOwner(string $owner, string $reason = self::REASON_PLUGIN_DEACTIVATED): int
    {
        if (! $this->tablesExist()) {
            return 0;
        }

        $keys = TrustedScriptApproval::query()
            ->where('owner_slug', $owner)
            ->where('status', TrustedScriptApproval::STATUS_APPROVED)
            ->pluck('script_key')
            ->all();

        $count = 0;

        foreach ($keys as $key) {
            $count += $this->revoke($owner, (string) $key, null, $reason) ? 1 : 0;
        }

        return $count;
    }

    /** Drop the request-scoped read snapshot. */
    public function forget(): void
    {
        try {
            request()->attributes->remove(self::REQUEST_KEY);
        } catch (Throwable) {
            // No request bound — nothing memoised.
        }
    }

    /**
     * All approval rows, memoised on the current request.
     *
     * @return array<string, array{status: string, fingerprint: string}>
     */
    private function rows(): array
    {
        try {
            $request = request();
            $cached = $request->attributes->get(self::REQUEST_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            $request = null;
        }

        $rows = [];

        try {
            if ($this->tablesExist()) {
                foreach (TrustedScriptApproval::query()->get(['owner_slug', 'script_key', 'status', 'fingerprint']) as $row) {
                    $rows[$this->id($row->owner_slug, $row->script_key)] = [
                        'status' => (string) $row->status,
                        'fingerprint' => (string) $row->fingerprint,
                    ];
                }
            }
        } catch (Throwable) {
            $rows = [];
        }

        $request?->attributes->set(self::REQUEST_KEY, $rows);

        return $rows;
    }

    private function event(string $owner, string $key, string $fingerprint, string $action, ?int $actorId): void
    {
        TrustedScriptApprovalEvent::query()->create([
            'owner_slug' => $owner,
            'script_key' => $key,
            'fingerprint' => $fingerprint,
            'action' => $action,
            'actor_id' => $actorId,
        ]);
    }

    private function tablesExist(): bool
    {
        try {
            return Schema::hasTable('cms_trusted_script_approvals')
                && Schema::hasTable('cms_trusted_script_approval_events');
        } catch (Throwable) {
            return false;
        }
    }

    private function id(string $owner, string $key): string
    {
        return $owner."\0".$key;
    }
}
