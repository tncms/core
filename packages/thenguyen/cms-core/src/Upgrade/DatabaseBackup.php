<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use PDO;
use RuntimeException;
use Throwable;

/**
 * PHP-native MySQL/MariaDB logical backup & restore (CORE-UPGRADE-1, §20/§34),
 * with a provable snapshot format (CORE-UPGRADE-DISK-QUOTA-P0-R1, format v2).
 *
 * Shared hosting frequently has NO mysqldump / mysql client and NO shell, but
 * DOES have PDO-MySQL. This is the sole, shell-free backup authority. It is
 * dependency-free (PDO only, no Laravel) so RESTORE can run during rollback even
 * when the target Core is broken and the framework cannot boot.
 *
 * Integrity model:
 *  - dump() reads every base table inside one `START TRANSACTION WITH CONSISTENT
 *    SNAPSHOT` (REPEATABLE READ): InnoDB tables are mutually consistent and each
 *    table's written row count is checked against COUNT(*) in the same snapshot.
 *    Non-transactional engines (MyISAM, MEMORY, …) are only consistent while the
 *    site is in maintenance; they are listed in the manifest.
 *  - Every write, flush, sync and close is checked ({@see DurableFile}). The dump
 *    is written to `<file>.partial`, ends with a `-- TNCMS-END` footer, is re-read
 *    and verified, and only then a checksum-bound manifest `<file>.manifest.json`
 *    is written and the dump renamed into place. A file without a valid manifest
 *    and footer is not a snapshot.
 *  - The manifest records, per table: a semantic schema signature, row count, a hash of
 *    the canonical row stream (ordered by primary key, else by every column) and
 *    a hash of that table's statements in the file. verify() re-derives the file
 *    hashes; verifyRestored() re-derives the schema signature and row hashes from the LIVE
 *    database, so a restore is proven against the snapshot, not merely executed.
 *  - Tables with triggers make the snapshot NOT restorable (DROP TABLE would
 *    silently discard the trigger); views and routines are not touched.
 *  - restore() refuses anything verify() rejects, and drops a table that is not
 *    in the snapshot ONLY when the caller names it as a proven post-snapshot
 *    table. It is idempotent: re-running it after an interruption converges.
 *  - Injection / fidelity hardening: dump, restore and verification run with a
 *    pinned session (`sql_mode` without NO_BACKSLASH_ESCAPES, plus
 *    NO_AUTO_VALUE_ON_ZERO; `time_zone` +00:00) and restore the caller's session
 *    afterwards, so quoted values never contain a raw newline; any statement that
 *    would contain the framing delimiter fails the dump closed. Columns are
 *    listed explicitly: GENERATED columns are skipped (MySQL rejects values for
 *    them) and INVISIBLE columns included (SELECT * would omit them).
 *    restore() verifies and executes the SAME bytes it read once (no TOCTOU).
 *  - Memory: verify and restore hold the dump in memory (~3x its size); the
 *    manager refuses automatic rollback when memory_limit cannot cover that.
 */
final class DatabaseBackup
{
    private const HEADER = '-- TNCMS PHP-native dump v1';

    private const FORMAT = 2;

    private const STMT = "\n-- TNCMS-STMT --\n";

    private const FOOTER = '-- TNCMS-END';

    private const ROWS_PER_INSERT = 100;

    private const DELIMITER = '-- TNCMS-STMT --';

    private const SESSION_SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION';

    /** Tables whose contents legitimately change while a site sits in maintenance. */
    public const VOLATILE_TABLES = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    /**
     * @param  (\Closure(string):bool)|null  $fault  test seam: return true to fail the named
     *                                               boundary (write|flush|close|rename|restore-stmt)
     */
    public function __construct(private ?\Closure $fault = null) {}

    public static function manifestPath(string $file): string
    {
        return $file.'.manifest.json';
    }

    /**
     * Dump every base table of the connected schema to $outFile.
     *
     * @return array{tables:int,rows:int,bytes:int,sha256:string,table_rows:array<string,int>,
     *               table_list:list<string>,manifest:string,manifest_sha256:string,restorable:bool,
     *               not_restorable_reason:?string,non_transactional:list<string>}
     *
     * @throws RuntimeException on any storage or consistency failure (no snapshot is left behind)
     */
    public function dump(PDO $pdo, string $outFile): array
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $dir = \dirname($outFile);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new UpgradeStorageException('database snapshot directory cannot be created', 'create_failed');
        }
        $partial = $outFile.'.partial';
        @unlink($partial);
        @unlink($outFile);
        @unlink(self::manifestPath($outFile));

        $fh = @fopen($partial, 'xb');
        if ($fh === false) {
            throw new UpgradeStorageException('database snapshot cannot be created', 'create_failed');
        }
        $sections = [];
        $current = '';
        $write = function (string $stmt) use ($fh, &$sections, &$current): void {
            if ($this->fails('write')) {
                throw new UpgradeStorageException('database snapshot: write failed', 'write_short');
            }
            if (str_contains($stmt, self::DELIMITER)) {
                throw new RuntimeException('database snapshot: a statement contains the snapshot delimiter');
            }
            $chunk = $stmt.self::STMT;
            DurableFile::writeAll($fh, $chunk, 'database snapshot');
            if ($current !== '') {
                hash_update($sections[$current], $chunk);
            }
        };

        $tables = [];
        $totalRows = 0;
        $nonTransactional = [];
        $triggers = [];
        $began = false;
        $session = $this->pinSession($pdo);
        try {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $began = true;

            $write(self::HEADER.' format='.self::FORMAT.' generated='.gmdate('c'));
            $write('SET FOREIGN_KEY_CHECKS=0');
            $write('SET NAMES utf8mb4');
            $write("SET SESSION sql_mode='".self::SESSION_SQL_MODE."'");
            $write("SET SESSION time_zone='+00:00'");

            $engines = $this->engines($pdo);
            $triggers = $this->triggerTables($pdo);
            foreach ($this->baseTables($pdo) as $table) {
                $q = self::q($table);
                $ddl = (string) ($pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_ASSOC)['Create Table'] ?? '');
                if ($ddl === '') {
                    continue;
                }
                $current = $table;
                $sections[$table] = hash_init('sha256');
                $write('DROP TABLE IF EXISTS '.$q);
                $write($ddl);
                [$rows, $dataSha] = $this->dumpRows($pdo, $table, $write);
                $counted = (int) $pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn();
                if ($counted !== $rows) {
                    throw new RuntimeException("database snapshot: row count mismatch in $table");
                }
                $current = '';
                $tables[$table] = [
                    'schema_sha256' => $this->schemaSignature($pdo, $table),
                    'rows' => $rows,
                    'data_sha256' => $dataSha,
                    'section_sha256' => hash_final($sections[$table]),
                    'engine' => $engines[$table] ?? null,
                ];
                if (! \in_array(strtolower((string) ($engines[$table] ?? '')), ['innodb', ''], true)) {
                    $nonTransactional[] = $table;
                }
                $totalRows += $rows;
            }
            $write('SET FOREIGN_KEY_CHECKS=1');
            $write(self::FOOTER.' tables='.\count($tables).' rows='.$totalRows);
            $pdo->exec('COMMIT');
            $began = false;
            if ($this->fails('flush')) {
                throw new UpgradeStorageException('database snapshot: flush failed', 'flush_failed');
            }
            if ($this->fails('close')) {
                @fclose($fh);
                throw new UpgradeStorageException('database snapshot: close failed', 'close_failed');
            }
            DurableFile::finish($fh, 'database snapshot');
            $fh = null;
        } catch (Throwable $e) {
            if ($began) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            if (\is_resource($fh)) {
                @fclose($fh);
            }
            @unlink($partial);
            $this->unpinSession($pdo, $session);
            throw $e instanceof RuntimeException && ! $e instanceof \PDOException ? $e : new RuntimeException('database snapshot failed: database error', 0, $e);
        }
        $this->unpinSession($pdo, $session);

        $restorable = $triggers === [];
        $manifest = [
            'format' => self::FORMAT,
            'generated_at' => gmdate('c'),
            'file_bytes' => (int) filesize($partial),
            'file_sha256' => (string) hash_file('sha256', $partial),
            'table_list' => array_keys($tables),
            'tables' => $tables,
            'rows' => $totalRows,
            'non_transactional' => $nonTransactional,
            'restorable' => $restorable,
            'not_restorable_reason' => $restorable ? null : 'tables have triggers: '.implode(', ', $triggers),
        ];

        try {
            $check = $this->verifyAgainst($partial, $manifest);
            if ($check !== null) {
                throw new RuntimeException('database snapshot failed re-verification: '.$check);
            }
            $manifestJson = (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            DurableFile::replace(self::manifestPath($outFile), $manifestJson, 'database snapshot manifest');
            if ($this->fails('rename') || ! DurableFile::rename($partial, $outFile)) {
                throw new UpgradeStorageException('database snapshot could not be finalized', 'rename_failed');
            }
        } catch (Throwable $e) {
            @unlink($partial);
            @unlink(self::manifestPath($outFile));
            throw $e instanceof RuntimeException && ! $e instanceof \PDOException ? $e : new RuntimeException('database snapshot failed: database error', 0, $e);
        }

        return [
            'tables' => \count($tables),
            'rows' => $totalRows,
            'bytes' => $manifest['file_bytes'],
            'sha256' => $manifest['file_sha256'],
            'table_rows' => array_map(static fn (array $t) => $t['rows'], $tables),
            'table_list' => array_keys($tables),
            'manifest' => self::manifestPath($outFile),
            'manifest_sha256' => hash('sha256', $manifestJson),
            'restorable' => $restorable,
            'not_restorable_reason' => $manifest['not_restorable_reason'],
            'non_transactional' => $nonTransactional,
        ];
    }

    /**
     * Restore a VERIFIED snapshot. Refuses (throws, executing nothing) when the
     * snapshot is incomplete, corrupt, unverifiable or not restorable, or when a
     * drop candidate is part of the snapshot.
     *
     * @param  list<string>  $dropCandidates  tables proven to be created after the snapshot
     * @return array{statements:int, dropped:list<string>}
     */
    public function restore(PDO $pdo, string $inFile, array $dropCandidates = [], ?string $expectedManifestSha256 = null): array
    {
        // Read ONCE: the bytes that are verified are the bytes that are executed.
        $bytes = @file_get_contents($inFile);
        $verify = \is_string($bytes)
            ? $this->verifyBytes($bytes, self::manifestPath($inFile), null, $expectedManifestSha256)
            : ['ok' => false, 'reason' => 'missing'];
        if (! $verify['ok']) {
            throw new RuntimeException('refusing to restore an unverified database snapshot: '.$verify['reason']);
        }
        $manifest = $verify['manifest'];
        $snapshotTables = array_flip(array_map('strval', $manifest['table_list']));
        foreach ($dropCandidates as $t) {
            if (isset($snapshotTables[(string) $t])) {
                throw new RuntimeException('refusing to drop a table that exists in the snapshot');
            }
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $session = $this->pinSession($pdo);
        $count = 0;
        $dropped = [];
        try {
            foreach (explode(self::STMT, (string) $bytes) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '' || str_starts_with($stmt, self::HEADER) || str_starts_with($stmt, self::FOOTER)) {
                    continue;
                }
                if ($this->fails('restore-stmt')) {
                    throw new RuntimeException('database restore interrupted');
                }
                $pdo->exec($stmt);
                $count++;
            }
            unset($bytes);

            $live = array_flip($this->baseTables($pdo));
            $candidates = array_values(array_filter(array_map('strval', $dropCandidates), static fn (string $t) => isset($live[$t])));
            if ($candidates !== []) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($candidates as $table) {
                    $pdo->exec('DROP TABLE IF EXISTS '.self::q($table));
                    $dropped[] = $table;
                }
            }
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable) {
            }
            $this->unpinSession($pdo, $session);
        }

        return ['statements' => $count, 'dropped' => $dropped];
    }

    /**
     * Verify a snapshot file against its manifest (§22): footer, byte length,
     * file SHA-256, per-table statement hashes, exact table inventory and
     * restorability. Optionally pin the manifest bytes to a recorded hash.
     *
     * @return array{ok:bool,reason:string,bytes:int,sha256:string,create_tables:int,manifest?:array<string,mixed>}
     */
    public function verify(string $file, ?string $expectedSha256 = null, ?string $expectedManifestSha256 = null): array
    {
        $bytes = is_file($file) ? @file_get_contents($file) : false;
        if (! \is_string($bytes)) {
            return ['ok' => false, 'reason' => 'missing', 'bytes' => 0, 'sha256' => '', 'create_tables' => 0];
        }

        return $this->verifyBytes($bytes, self::manifestPath($file), $expectedSha256, $expectedManifestSha256);
    }

    /**
     * @return array{ok:bool,reason:string,bytes:int,sha256:string,create_tables:int,manifest?:array<string,mixed>}
     */
    private function verifyBytes(string $bytes, string $manifestFile, ?string $expectedSha256, ?string $expectedManifestSha256): array
    {
        $len = \strlen($bytes);
        $sha = hash('sha256', $bytes);
        $creates = (int) preg_match_all('/^CREATE TABLE /mi', $bytes);
        $fail = static fn (string $reason) => ['ok' => false, 'reason' => $reason, 'bytes' => $len, 'sha256' => $sha, 'create_tables' => $creates];
        if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $sha)) {
            return $fail('checksum_mismatch');
        }
        $raw = @file_get_contents($manifestFile);
        if (! \is_string($raw) || $raw === '') {
            return $fail('manifest_missing');
        }
        if ($expectedManifestSha256 !== null && ! hash_equals($expectedManifestSha256, hash('sha256', $raw))) {
            return $fail('manifest_mismatch');
        }
        $manifest = json_decode($raw, true);
        if (! \is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            return $fail('manifest_invalid');
        }
        $problem = $this->verifyAgainstBytes($bytes, $manifest);
        if ($problem !== null) {
            return $fail($problem);
        }
        if (! ($manifest['restorable'] ?? false)) {
            return $fail('not_restorable');
        }

        return ['ok' => true, 'reason' => 'ok', 'bytes' => $len, 'sha256' => $sha, 'create_tables' => $creates, 'manifest' => $manifest];
    }

    /**
     * Prove a restore: every snapshot table exists with the recorded DDL, row
     * count and canonical row-stream hash. Volatile tables are checked for
     * existence and DDL only.
     *
     * @return list<array{rel:string,reason:string}> empty when verified
     */
    public function verifyRestored(PDO $pdo, string $file, ?string $expectedManifestSha256 = null): array
    {
        $raw = @file_get_contents(self::manifestPath($file));
        $manifest = \is_string($raw) ? json_decode($raw, true) : null;
        if (! \is_array($manifest) || ($expectedManifestSha256 !== null && ! hash_equals($expectedManifestSha256, hash('sha256', (string) $raw)))) {
            return [['rel' => 'database', 'reason' => 'snapshot manifest unavailable']];
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $session = $this->pinSession($pdo);
        try {
            return $this->compareRestored($pdo, $manifest);
        } finally {
            $this->unpinSession($pdo, $session);
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array{rel:string,reason:string}>
     */
    private function compareRestored(PDO $pdo, array $manifest): array
    {
        $live = array_flip($this->baseTables($pdo));
        $problems = [];
        foreach ($manifest['tables'] as $table => $expect) {
            $table = (string) $table;
            if (! isset($live[$table])) {
                $problems[] = ['rel' => "database:$table", 'reason' => 'table missing after restore'];

                continue;
            }
            if (! hash_equals((string) ($expect['schema_sha256'] ?? ''), $this->schemaSignature($pdo, $table))) {
                $problems[] = ['rel' => "database:$table", 'reason' => 'schema differs from the snapshot'];

                continue;
            }
            if (\in_array($table, self::VOLATILE_TABLES, true)) {
                continue;
            }
            [$rows, $dataSha] = $this->dumpRows($pdo, $table, null);
            if ($rows !== (int) $expect['rows'] || ! hash_equals((string) $expect['data_sha256'], $dataSha)) {
                $problems[] = ['rel' => "database:$table", 'reason' => 'data differs from the snapshot'];
            }
        }

        return $problems;
    }

    /** @return list<string> base table names of the current schema */
    public function baseTables(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /** Estimated dump size in bytes (data + index length), for capacity planning only. */
    public function estimateBytes(PDO $pdo): ?int
    {
        try {
            $v = $pdo->query("SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();

            return $v === null || $v === false ? 0 : (int) $v;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * table => "rows:CHECKSUM TABLE" — a cheap content fingerprint.
     *
     * @return array<string,string>
     */
    public function fingerprint(PDO $pdo): array
    {
        $out = [];
        foreach ($this->baseTables($pdo) as $table) {
            $q = self::q($table);
            $rows = (int) $pdo->query("SELECT COUNT(*) FROM $q")->fetchColumn();
            $sum = (string) ($pdo->query("CHECKSUM TABLE $q")->fetch(PDO::FETCH_ASSOC)['Checksum'] ?? '');
            $out[$table] = "$rows:$sum";
        }

        return $out;
    }

    // ---- internals -------------------------------------------------------

    /** null when $file matches $manifest exactly, else a reason code */
    private function verifyAgainst(string $file, array $manifest): ?string
    {
        $bytes = @file_get_contents($file);

        return \is_string($bytes) ? $this->verifyAgainstBytes($bytes, $manifest) : 'missing';
    }

    /** null when $bytes match $manifest exactly, else a reason code */
    private function verifyAgainstBytes(string $bytes, array $manifest): ?string
    {
        if (\strlen($bytes) !== (int) ($manifest['file_bytes'] ?? -1)) {
            return 'length_mismatch';
        }
        if (! hash_equals((string) ($manifest['file_sha256'] ?? ''), hash('sha256', $bytes))) {
            return 'checksum_mismatch';
        }
        $parts = explode(self::STMT, $bytes);
        if (($parts[0] ?? '') === '' || ! str_starts_with($parts[0], self::HEADER)) {
            return 'bad_header';
        }
        $footer = trim((string) ($parts[\count($parts) - 2] ?? ''));
        $tables = \is_array($manifest['tables'] ?? null) ? $manifest['tables'] : [];
        $list = \is_array($manifest['table_list'] ?? null) ? $manifest['table_list'] : [];
        if ($list !== array_keys($tables)) {
            return 'inventory_mismatch';
        }
        if ($footer !== self::FOOTER.' tables='.\count($tables).' rows='.(int) ($manifest['rows'] ?? -1) || end($parts) !== '') {
            return 'incomplete';
        }
        $sections = [];
        $current = null;
        foreach ($parts as $stmt) {
            if (preg_match('/^DROP TABLE IF EXISTS `((?:[^`]|``)+)`$/', $stmt, $m) === 1) {
                $current = str_replace('``', '`', $m[1]);
                if (isset($sections[$current])) {
                    return 'duplicate_table';
                }
                $sections[$current] = hash_init('sha256');
            } elseif ($current !== null && (str_starts_with($stmt, 'SET FOREIGN_KEY_CHECKS=1') || str_starts_with($stmt, self::FOOTER))) {
                $current = null;
            }
            if ($current !== null) {
                hash_update($sections[$current], $stmt.self::STMT);
            }
        }
        if (array_map('strval', array_keys($sections)) !== array_map('strval', $list)) {
            return 'inventory_mismatch';
        }
        $rowSum = 0;
        foreach ($tables as $table => $t) {
            if (! isset($sections[$table]) || ! hash_equals((string) ($t['section_sha256'] ?? ''), hash_final($sections[$table]))) {
                return 'table_mismatch';
            }
            $rowSum += (int) ($t['rows'] ?? 0);
        }

        return $rowSum === (int) ($manifest['rows'] ?? -1) ? null : 'row_count_mismatch';
    }

    /**
     * Stream one table's rows in a canonical order as batched INSERT statements
     * (when $write is given) and return [row count, SHA-256 of the canonical row stream].
     *
     * @return array{int,string}
     */
    private function dumpRows(PDO $pdo, string $table, ?callable $write): array
    {
        $columns = $this->restorableColumns($pdo, $table);
        $select = $columns === [] ? '*' : implode(',', array_map(static fn ($c) => self::q($c), $columns));
        $stmt = $pdo->query('SELECT '.$select.' FROM '.self::q($table).' ORDER BY '.$this->orderBy($pdo, $table));
        $rowCount = 0;
        $batch = [];
        $ctx = hash_init('sha256');

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($columns === []) {
                $columns = array_map('strval', array_keys($row));
            }
            $tuple = '('.implode(',', array_map(fn ($v) => $this->encodeValue($pdo, $v), array_values($row))).')';
            hash_update($ctx, $tuple."\n");
            $rowCount++;
            if ($write !== null) {
                $batch[] = $tuple;
                if (\count($batch) >= self::ROWS_PER_INSERT) {
                    $write($this->insertStatement($table, $columns, $batch));
                    $batch = [];
                }
            }
        }
        if ($write !== null && $batch !== [] && $columns !== []) {
            $write($this->insertStatement($table, $columns, $batch));
        }

        return [$rowCount, hash_final($ctx)];
    }

    /**
     * Columns whose values can be inserted back: GENERATED columns are excluded
     * (MySQL rejects explicit values for them), INVISIBLE columns are included
     * (SELECT * would silently omit them).
     *
     * @return list<string>
     */
    private function restorableColumns(PDO $pdo, string $table): array
    {
        $rows = $pdo->query(
            'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            .$pdo->quote($table).' ORDER BY ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if (stripos((string) ($r['EXTRA'] ?? ''), 'GENERATED') === false) {
                $out[] = (string) $r['COLUMN_NAME'];
            }
        }

        return $out;
    }

    /**
     * Pin the session so quoting, auto-increment and temporal values are
     * reproducible; returns the previous settings for unpinSession().
     *
     * @return array<string,?string>
     */
    private function pinSession(PDO $pdo): array
    {
        $prev = ['sql_mode' => null, 'time_zone' => null, 'transaction_isolation' => null, 'max_sort_length' => null];
        try {
            $row = $pdo->query('SELECT @@SESSION.sql_mode AS sql_mode, @@SESSION.time_zone AS time_zone, '
                .'@@SESSION.transaction_isolation AS transaction_isolation, @@SESSION.max_sort_length AS max_sort_length')->fetch(PDO::FETCH_ASSOC);
            foreach (array_keys($prev) as $k) {
                $prev[$k] = isset($row[$k]) ? (string) $row[$k] : null;
            }
        } catch (Throwable) {
        }
        $pdo->exec("SET SESSION sql_mode='".self::SESSION_SQL_MODE."'");
        $pdo->exec("SET SESSION time_zone='+00:00'");
        $pdo->exec('SET SESSION max_sort_length=8388608'); // no truncated sort keys in the canonical order

        return $prev;
    }

    /** @param array<string,?string> $prev */
    private function unpinSession(PDO $pdo, array $prev): void
    {
        foreach ($prev as $name => $value) {
            if ($value === null) {
                continue;
            }
            try {
                $pdo->exec('SET SESSION '.$name.'='.(ctype_digit($value) ? $value : $pdo->quote($value)));
            } catch (Throwable) {
                // best effort: the session is the upgrade's own connection
            }
        }
    }

    /** Primary-key order, else every column, so the row stream is deterministic. */
    private function orderBy(PDO $pdo, string $table): string
    {
        $cols = $pdo->query(
            'SELECT COLUMN_NAME, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '
            .$pdo->quote($table).' ORDER BY ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_ASSOC);
        $pk = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' AND TABLE_NAME = "
            .$pdo->quote($table).' ORDER BY ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($pk !== []) {
            return implode(',', array_map(static fn ($c) => self::q((string) $c), $pk));
        }
        $all = array_column($cols, 'COLUMN_NAME');

        // No primary key: byte order (not collation order), so case-insensitive ties are deterministic.
        return $all === [] ? '1' : implode(',', array_map(static fn ($c) => 'CAST('.self::q((string) $c).' AS BINARY)', $all));
    }

    /** @return array<string,string> table => engine */
    private function engines(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t, $e]) {
            $out[(string) $t] = (string) $e;
        }

        return $out;
    }

    /** @return list<string> tables that carry triggers (they block automatic rollback) */
    public function triggerTables(PDO $pdo): array
    {
        return array_values(array_unique(array_map('strval', $pdo->query(
            'SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE'
        )->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * Semantic schema signature from information_schema: engine + table
     * collation, every column (type, nullability, default, extra, charset,
     * collation, generation expression), every index part and every foreign
     * key. Unlike SHOW CREATE TABLE text it is stable when MySQL merely
     * re-renders an identical definition (e.g. an explicit column CHARACTER SET
     * after a restore under a different connection collation), and still
     * changes on any real schema difference.
     */
    private function schemaSignature(PDO $pdo, string $table): string
    {
        $t = $pdo->quote($table);
        $parts = [
            'table' => $pdo->query("SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = $t")->fetchAll(PDO::FETCH_NUM),
            'columns' => $pdo->query('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION '
                ."FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = $t ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_NUM),
            'indexes' => $pdo->query('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, EXPRESSION '
                ."FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = $t ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_NUM),
            'foreign_keys' => $pdo->query('SELECT k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE '
                .'FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                .'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME '
                ."WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = $t AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION")->fetchAll(PDO::FETCH_NUM),
        ];

        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function q(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    private function fails(string $point): bool
    {
        return $this->fault !== null && ($this->fault)($point) === true;
    }

    /** @param list<string> $columns @param list<string> $tuples */
    private function insertStatement(string $table, array $columns, array $tuples): string
    {
        $cols = implode(',', array_map(static fn ($c) => self::q((string) $c), $columns));

        return 'INSERT INTO '.self::q($table)." ($cols) VALUES ".implode(',', $tuples);
    }

    /** Encode a single value as a MySQL literal, preserving NULL/binary/text. */
    private function encodeValue(PDO $pdo, mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (\is_int($v) || \is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        // Non-UTF-8 payloads (BLOB/BINARY) → unquoted hex literal, lossless.
        if ($s !== '' && ! mb_check_encoding($s, 'UTF-8')) {
            return '0x'.bin2hex($s);
        }

        try {
            return $pdo->quote($s);
        } catch (Throwable) {
            return '0x'.bin2hex($s);
        }
    }
}
