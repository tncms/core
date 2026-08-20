<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;

/**
 * Durable upgrade state persistence (CORE-UPGRADE-1, §4/§5/§44/§45).
 *
 * Persists one JSON record per upgrade attempt under a protected storage root
 * (NOT the PHP session, NOT public/) so a state survives ordinary browser
 * refreshes and an interrupted upgrade is detectable on the next /upgrade visit.
 * The record is the durable source of truth; the controller reads it, never a
 * session flag.
 *
 * Only secret-safe fields are written — paths, checksums, versions, timestamps,
 * stage names and a history trail. Database passwords, .env contents and the
 * super-admin credential NEVER appear here (§45/§50).
 *
 * State vocabulary + legal transitions belong to {@see UpgradeState}; this class
 * only reads/writes and appends history, delegating every transition to the
 * state machine so idempotency has one definition.
 */
final class UpgradeStateStore
{
    private string $root;

    /** @param string $root protected base dir, default storage/app/upgrades */
    public function __construct(?string $root = null)
    {
        $base = $root ?? (\function_exists('storage_path') ? storage_path('app/upgrades') : sys_get_temp_dir().'/tncms-upgrades');
        $this->root = rtrim(str_replace('\\', '/', $base), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function dir(string $id): string
    {
        return $this->root.'/'.$this->safeId($id);
    }

    public function stateFile(string $id): string
    {
        return $this->dir($id).'/state.json';
    }

    /**
     * Create a fresh state record for a new upgrade attempt.
     *
     * @param  array<string,mixed>  $data  secret-safe initial fields
     * @return array<string,mixed>
     */
    public function create(string $id, array $data = []): array
    {
        $id = $this->safeId($id);
        $dir = $this->dir($id);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create upgrade state dir: $dir");
        }

        $now = $this->now();
        $state = array_merge([
            'upgrade_id' => $id,
            'status' => UpgradeState::UPLOADED,
            'source_version' => null,
            'target_version' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'failure_stage' => null,
            'failure_reason' => null,
            'history' => [[
                'status' => UpgradeState::UPLOADED,
                'at' => $now,
                'note' => 'Package uploaded',
            ]],
        ], $data);
        $state['upgrade_id'] = $id;
        $state['status'] = $state['status'] ?? UpgradeState::UPLOADED;

        $this->write($id, $state);

        return $state;
    }

    /** @return array<string,mixed>|null */
    public function load(string $id): ?array
    {
        $file = $this->stateFile($id);
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : null;
    }

    /**
     * Merge secret-safe fields into an existing record without changing status.
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    public function patch(string $id, array $fields): array
    {
        $state = $this->load($id) ?? throw new RuntimeException("no upgrade state: $id");
        foreach ($fields as $k => $v) {
            $state[$k] = $v;
        }
        $state['updated_at'] = $this->now();
        $this->write($id, $state);

        return $state;
    }

    /**
     * Advance the record to a new status through the state machine, appending a
     * history entry. An illegal/duplicate transition throws (§7). When moving to
     * FAILED, records the failure stage (the status we were in) + reason.
     *
     * @param  array<string,mixed>  $fields  extra secret-safe fields to merge
     * @return array<string,mixed>
     */
    public function transition(string $id, string $to, string $note = '', array $fields = []): array
    {
        $state = $this->load($id) ?? throw new RuntimeException("no upgrade state: $id");
        $from = (string) ($state['status'] ?? '');

        UpgradeState::assertTransition($from, $to);

        if ($to === UpgradeState::FAILED) {
            $state['failure_stage'] = $state['failure_stage'] ?? $from;
            if ($note !== '') {
                $state['failure_reason'] = $note;
            }
        }

        foreach ($fields as $k => $v) {
            $state[$k] = $v;
        }

        $now = $this->now();
        $state['status'] = $to;
        $state['updated_at'] = $now;
        $history = $state['history'] ?? [];
        $history[] = ['status' => $to, 'at' => $now, 'note' => $note];
        $state['history'] = $history;

        $this->write($id, $state);

        return $state;
    }

    /**
     * The most recently updated upgrade record, or null when none exist.
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $best = null;
        foreach ($this->all() as $state) {
            if ($best === null || ($state['updated_at'] ?? '') > ($best['updated_at'] ?? '')) {
                $best = $state;
            }
        }

        return $best;
    }

    /**
     * The latest NON-terminal upgrade — an in-flight or interrupted attempt (§44).
     *
     * @return array<string,mixed>|null
     */
    public function active(): ?array
    {
        $latest = $this->latest();
        if ($latest === null) {
            return null;
        }

        return UpgradeState::isTerminal((string) ($latest['status'] ?? '')) ? null : $latest;
    }

    /** @return list<array<string,mixed>> every persisted record */
    public function all(): array
    {
        if (! is_dir($this->root)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            // Only per-attempt id directories are state records; skip the lock
            // file and anything else living in the upgrades root.
            if (! UpgradeId::isValid($entry) || ! is_dir($this->root.'/'.$entry)) {
                continue;
            }
            $state = $this->load($entry);
            if ($state !== null) {
                $out[] = $state;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $state */
    private function write(string $id, array $state): void
    {
        $file = $this->stateFile($id);
        $dir = \dirname($file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create upgrade state dir: $dir");
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (@file_put_contents($file, (string) $json) === false) {
            throw new RuntimeException("cannot write upgrade state: $file");
        }
    }

    /** Reject anything that is not a plain upgrade id (no traversal, no slashes). */
    private function safeId(string $id): string
    {
        if (preg_match('/^upg_[0-9]{8,14}_[0-9a-f]{6,16}$/', $id) !== 1) {
            throw new RuntimeException('invalid upgrade id');
        }

        return $id;
    }

    private function now(): string
    {
        return gmdate('c');
    }
}
