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
 *
 * Durability (CORE-UPGRADE-DISK-QUOTA-P0-R1): each record carries a monotonic
 * `generation` and an `integrity` SHA-256 over its canonical JSON. A write goes
 * to a verified temp file ({@see DurableFile}); the current `state.json` is then
 * renamed to `state.prev.json` and the temp file renamed into place, so a failed
 * or torn write never destroys the last good record. load() returns the newest
 * valid generation among state.json / state.prev.json / leftover temp files and
 * flags `state_degraded` when it had to fall back. An attempt directory whose
 * state cannot be read at all is still reported by all() as a degraded record,
 * so "no prior upgrade" is never inferred from unreadable state.
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
        $id = $this->safeId($id);
        $best = null;
        $primaryValid = false;
        $records = [];
        foreach ($this->candidates($id) as $candidate) {
            $data = self::decode($candidate);
            if ($data !== null && (string) $data['upgrade_id'] === $id) {
                $records[$candidate] = $data;
            }
        }
        // Once any record carries an integrity hash, an unhashed (legacy-looking) record is not trusted.
        $hashed = array_filter($records, static fn (array $r) => (bool) ($r['__hashed'] ?? false));
        if ($hashed !== []) {
            $records = $hashed;
        }
        // A leftover temp file is only the generation directly after the newest promoted record.
        $promoted = array_filter($records, static fn ($k) => ! str_contains((string) $k, '.tmp-'), ARRAY_FILTER_USE_KEY);
        $top = $promoted === [] ? 0 : max(array_map(static fn (array $r) => (int) ($r['generation'] ?? 0), $promoted));
        foreach ($records as $candidate => $data) {
            if (str_contains((string) $candidate, '.tmp-') && (int) ($data['generation'] ?? 0) !== $top + 1) {
                continue;
            }
            unset($data['__hashed']);
            if ($candidate === $file) {
                $primaryValid = true;
            }
            if ($best === null || (int) ($data['generation'] ?? 0) > (int) ($best['generation'] ?? 0)) {
                $best = $data;
                $best['__from'] = $candidate;
            }
        }
        if ($best === null) {
            return null;
        }
        $from = $best['__from'];
        unset($best['__from']);
        if (! $primaryValid || $from !== $file) {
            $best['state_degraded'] = true;
        }

        return $best;
    }

    /**
     * True when the attempt directory holds state or transaction artifacts but
     * no state record can be read (zero-byte, malformed or corrupt).
     */
    public function isUnreadable(string $id): bool
    {
        $dir = $this->dir($id);

        return is_dir($dir) && $this->load($id) === null && (
            $this->candidates($id) !== []
            || is_dir($dir.'/txn') || is_dir($dir.'/backup') || is_dir($dir.'/staging') || is_file($dir.'/package.zip')
        );
    }

    /**
     * Write a fresh record for an attempt whose state could not be read, after
     * its recovery ran (generation continues above anything on disk).
     *
     * @param  array<string,mixed>  $fields
     * @return array<string,mixed>
     */
    public function rebuild(string $id, array $fields): array
    {
        $now = $this->now();
        $state = array_merge([
            'upgrade_id' => $this->safeId($id),
            'status' => UpgradeState::FAILED,
            'created_at' => $now,
            'history' => [['status' => UpgradeState::FAILED, 'at' => $now, 'note' => 'State rebuilt after an unreadable record']],
        ], $fields, ['updated_at' => $now, 'state_rebuilt' => true]);
        $this->write($id, $state);

        return $state;
    }

    /** @return list<string> existing state files for $id (primary, previous, leftover temps) */
    private function candidates(string $id): array
    {
        $dir = $this->dir($id);
        $out = [];
        foreach (['state.json', 'state.prev.json'] as $name) {
            if (is_file($dir.'/'.$name)) {
                $out[] = $dir.'/'.$name;
            }
        }
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $entry) {
            if (preg_match('/^state\.json\.tmp-[0-9a-f]{8}$/', $entry) === 1) {
                $out[] = $dir.'/'.$entry; // fully verified before rename; may be newer than state.json after a crash
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null a record whose integrity checks out (legacy records have none) */
    private static function decode(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if (! \is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (! \is_array($data) || ! isset($data['upgrade_id'], $data['status'])) {
            return null;
        }
        $hashed = \array_key_exists('integrity', $data);
        if ($hashed) {
            $claimed = (string) $data['integrity'];
            unset($data['integrity']);
            if (! hash_equals(self::digest($data), $claimed)) {
                return null;
            }
        }
        $data['__hashed'] = $hashed;

        return $data;
    }

    /** @param array<string,mixed> $state */
    private static function digest(array $state): string
    {
        return hash('sha256', (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
            } elseif ($this->isUnreadable($entry)) {
                // Never let unreadable state look like "no prior upgrade".
                $out[] = [
                    'upgrade_id' => $entry,
                    'status' => 'unknown',
                    'outcome' => UpgradeOutcome::RECOVERY_REQUIRED,
                    'state_degraded' => true,
                    'state_unreadable' => true,
                    'updated_at' => gmdate('c', (int) @filemtime($this->root.'/'.$entry)),
                ];
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
            throw new RuntimeException('cannot create upgrade state dir');
        }
        unset($state['state_degraded'], $state['integrity']);
        $current = $this->load($id);
        $state['generation'] = max((int) ($state['generation'] ?? 0), (int) ($current['generation'] ?? 0)) + 1;
        $state['integrity'] = self::digest($state);
        $json = (string) json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $tmp = DurableFile::writeTemp($file, $json, 'upgrade state');
        } catch (UpgradeStorageException $e) {
            throw new RuntimeException('cannot write upgrade state: '.$e->reason, 0, $e);
        }
        // Keep the last good record as the previous generation, then promote.
        if (is_file($file) && self::decode($file) !== null && ! DurableFile::rename($file, $dir.'/state.prev.json')) {
            @unlink($tmp);
            throw new RuntimeException('cannot write upgrade state: rename_failed');
        }
        if (! DurableFile::rename($tmp, $file)) {
            // Reported as not persisted, so the temp must not be adopted later; state.prev.json stays readable.
            @unlink($tmp);
            throw new RuntimeException('cannot write upgrade state: rename_failed');
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if (preg_match('/^state\.json\.tmp-[0-9a-f]{8}$/', $entry) === 1) {
                @unlink($dir.'/'.$entry); // stale temps from earlier interrupted writes
            }
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
