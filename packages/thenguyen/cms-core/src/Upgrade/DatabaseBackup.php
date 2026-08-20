<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use PDO;
use RuntimeException;
use Throwable;

/**
 * PHP-native MySQL/MariaDB logical backup & restore (CORE-UPGRADE-1, §20/§34).
 *
 * Shared hosting frequently has NO mysqldump / mysql client and NO shell, but
 * DOES have PDO-MySQL. This is the sole, shell-free backup authority: it dumps
 * schema + data using only PDO and restores from that dump. It is deliberately
 * dependency-free (PDO only, no Laravel) so RESTORE can run during rollback even
 * when the target Core is broken and the framework cannot boot.
 *
 * Fidelity: schema is captured verbatim via SHOW CREATE TABLE (engine, charset,
 * collation, indexes, foreign keys, AUTO_INCREMENT, nullability, defaults). Data
 * is encoded per value: NULL, numeric/decimal/date/text as quoted literals
 * (MySQL coerces), and non-UTF-8 (BLOB/BINARY) as unquoted 0x-hex literals.
 *
 * Format: statements are separated by a unique marker line so the parser never
 * mis-splits on ';' or ' -- ' appearing inside data. Views/triggers/stored
 * routines are OUT OF SCOPE for V1 (documented limitation); TNCMS core schema is
 * plain base tables.
 */
final class DatabaseBackup
{
    private const HEADER = '-- TNCMS PHP-native dump v1';

    private const STMT = "\n-- TNCMS-STMT --\n";

    private const ROWS_PER_INSERT = 100;

    /**
     * Dump every base table of the connected schema to $outFile.
     *
     * @return array{tables:int,rows:int,bytes:int,sha256:string,table_rows:array<string,int>}
     */
    public function dump(PDO $pdo, string $outFile): array
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $dir = \dirname($outFile);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException("cannot create backup dir: $dir");
        }
        $fh = fopen($outFile, 'wb');
        if ($fh === false) {
            throw new RuntimeException("cannot open backup file for write: $outFile");
        }

        $write = static function (string $stmt) use ($fh): void {
            fwrite($fh, $stmt . self::STMT);
        };

        fwrite($fh, self::HEADER . ' generated=' . gmdate('c') . self::STMT);
        $write('SET FOREIGN_KEY_CHECKS=0');
        $write('SET NAMES utf8mb4');

        $tables = $this->baseTables($pdo);
        $totalRows = 0;
        $perTable = [];

        foreach ($tables as $table) {
            $q = $table; // identifier quoting for SHOW/SELECT
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $q) . '`')
                ->fetch(PDO::FETCH_ASSOC);
            $ddl = $create['Create Table'] ?? ($create['Create View'] ?? null);
            if ($ddl === null) {
                continue; // not a base table
            }
            $write('DROP TABLE IF EXISTS `' . str_replace('`', '``', $q) . '`');
            $write($ddl);

            $rows = $this->dumpRows($pdo, $q, $write);
            $perTable[$q] = $rows;
            $totalRows += $rows;
        }

        $write('SET FOREIGN_KEY_CHECKS=1');
        fclose($fh);

        return [
            'tables' => \count($tables),
            'rows' => $totalRows,
            'bytes' => (int) filesize($outFile),
            'sha256' => hash_file('sha256', $outFile),
            'table_rows' => $perTable,
        ];
    }

    /**
     * Restore a dump produced by {@see dump()} into the connected (empty) schema.
     * Runs with only PDO — no framework boot required.
     *
     * @return array{statements:int}
     */
    public function restore(PDO $pdo, string $inFile): array
    {
        if (! is_file($inFile)) {
            throw new RuntimeException("backup file not found: $inFile");
        }
        $sql = (string) file_get_contents($inFile);
        if (! str_starts_with($sql, self::HEADER)) {
            throw new RuntimeException('unrecognized backup format (missing header)');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $count = 0;
        foreach (explode(self::STMT, $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, self::HEADER)) {
                continue;
            }
            $pdo->exec($stmt);
            $count++;
        }

        return ['statements' => $count];
    }

    /**
     * Lightweight structural verification of a dump file (§22): readable, header
     * present, has schema statements, and the recorded checksum matches. This is
     * a fast gate; the authoritative proof is a full dump→restore round-trip.
     *
     * @return array{ok:bool,reason:string,bytes:int,sha256:string,create_tables:int}
     */
    public function verify(string $file, ?string $expectedSha256 = null): array
    {
        if (! is_file($file)) {
            return ['ok' => false, 'reason' => 'missing', 'bytes' => 0, 'sha256' => '', 'create_tables' => 0];
        }
        $bytes = (int) filesize($file);
        $sha = hash_file('sha256', $file);
        $sql = (string) file_get_contents($file);

        if (! str_starts_with($sql, self::HEADER)) {
            return ['ok' => false, 'reason' => 'bad_header', 'bytes' => $bytes, 'sha256' => $sha, 'create_tables' => 0];
        }
        $creates = preg_match_all('/^CREATE TABLE /mi', $sql);
        if ($bytes <= 0) {
            return ['ok' => false, 'reason' => 'empty', 'bytes' => $bytes, 'sha256' => $sha, 'create_tables' => $creates];
        }
        if ($creates < 1) {
            return ['ok' => false, 'reason' => 'no_schema', 'bytes' => $bytes, 'sha256' => $sha, 'create_tables' => $creates];
        }
        if ($expectedSha256 !== null && ! hash_equals($expectedSha256, $sha)) {
            return ['ok' => false, 'reason' => 'checksum_mismatch', 'bytes' => $bytes, 'sha256' => $sha, 'create_tables' => $creates];
        }

        return ['ok' => true, 'reason' => 'ok', 'bytes' => $bytes, 'sha256' => $sha, 'create_tables' => $creates];
    }

    /** @return list<string> base table names of the current schema */
    private function baseTables(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $rows);
    }

    /** Stream one table's rows as batched INSERT statements. Returns row count. */
    private function dumpRows(PDO $pdo, string $table, callable $write): int
    {
        $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`');
        $rowCount = 0;
        $batch = [];
        $columns = null;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $values = array_map(fn ($v) => $this->encodeValue($pdo, $v), array_values($row));
            $batch[] = '(' . implode(',', $values) . ')';
            $rowCount++;

            if (\count($batch) >= self::ROWS_PER_INSERT) {
                $write($this->insertStatement($table, $columns, $batch));
                $batch = [];
            }
        }
        if ($batch !== [] && $columns !== null) {
            $write($this->insertStatement($table, $columns, $batch));
        }

        return $rowCount;
    }

    /** @param list<string> $columns @param list<string> $tuples */
    private function insertStatement(string $table, array $columns, array $tuples): string
    {
        $cols = implode(',', array_map(static fn ($c) => '`' . str_replace('`', '``', $c) . '`', $columns));

        return 'INSERT INTO `' . str_replace('`', '``', $table) . "` ($cols) VALUES " . implode(',', $tuples);
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
            return '0x' . bin2hex($s);
        }

        try {
            return $pdo->quote($s);
        } catch (Throwable) {
            return '0x' . bin2hex($s);
        }
    }
}
