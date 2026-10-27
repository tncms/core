<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;

/**
 * Append-only, line-delimited JSON journal of one Core upgrade transaction
 * (CORE-UPGRADE-WIN-R1).
 *
 * Every live mutation is preceded by an intent record, so a crash between the
 * intent and the effect is detectable. Each append is flushed and, where the
 * runtime supports it, fsync'd (PHP >= 8.1). OS limit: on Windows fsync maps to
 * FlushFileBuffers on the file only — directory entries are not separately
 * synced, which is why recovery derives the truth from the plan and the
 * OBSERVED filesystem, using the journal for phase and diagnostics.
 *
 * Dependency-free (no framework) so recovery can run when Laravel cannot boot.
 *
 * Never read or written through a link (CORE-UPGRADE-DISK-QUOTA-P0-R4): the
 * journal and its directory must be plain entries and the journal must have no
 * other name (hard link). PHP has no O_NOFOLLOW, so the check is repeated on the
 * open handle: its file identity must equal the path's, and the directory must
 * still be plain, before a byte is written or read. Residual limit: an `ab` open
 * through a link swapped in during that window may create an EMPTY file at the
 * link target; it is never written to or truncated.
 */
final class UpgradeJournal
{
    public const UNSAFE = 'the upgrade journal is behind a link or has another name; it was not read or written';

    public const UNREADABLE = 'the upgrade journal cannot be opened';

    private ?int $seq = null;

    /** @param (callable():void)|null $beforeOpen test seam: runs between the path check and the open */
    public function __construct(private string $file, private $beforeOpen = null)
    {
        $this->file = str_replace('\\', '/', $file);
    }

    /**
     * True when the journal (if present), its directory (txn/) and that directory's parent (the attempt
     * directory) are plain, single-named filesystem entries. Both directories are created by the engine,
     * so a link there is never legitimate; directories above the upgrade store are the operator's.
     */
    public function isSafe(): bool
    {
        clearstatcache();
        $txn = \dirname($this->file);
        if (UpgradePathGuard::isReparsePoint(\dirname($txn)) || UpgradePathGuard::isReparsePoint($txn) || UpgradePathGuard::isReparsePoint($this->file)) {
            return false;
        }
        $st = @lstat($this->file);

        return ! \is_array($st) || (int) $st['nlink'] <= 1;
    }

    /** @return resource an open handle proven to be the plain journal file itself */
    private function open(string $mode)
    {
        if (! $this->isSafe()) {
            throw new RuntimeException(self::UNSAFE);
        }
        if ($this->beforeOpen !== null) {
            ($this->beforeOpen)();
        }
        $fh = @fopen($this->file, $mode);
        if ($fh === false) {
            throw new RuntimeException(self::UNREADABLE);
        }
        $opened = @fstat($fh);
        clearstatcache();
        $named = @lstat($this->file);
        if (! \is_array($opened) || ! \is_array($named) || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            || (int) $opened['nlink'] > 1 || ! $this->isSafe()) {
            fclose($fh);

            throw new RuntimeException(self::UNSAFE);
        }

        return $fh;
    }

    public function path(): string
    {
        return $this->file;
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    /** @param array<string,mixed> $data */
    public function append(string $event, array $data = []): void
    {
        $dir = \dirname($this->file);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('upgrade journal directory cannot be created');
        }
        $this->seq ??= \count($this->events());
        $this->seq++;
        $line = json_encode(['seq' => $this->seq, 'at' => gmdate('c'), 'ev' => $event] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $fh = $this->open('ab');
        $osError = ''; // the OS reason PHP reports (e.g. "errno=28 No space left on device"), kept for the operator
        set_error_handler(static function (int $no, string $message) use (&$osError): bool {
            $osError = $message;

            return true;
        });
        try {
            $ok = fwrite($fh, $line) === \strlen($line) && fflush($fh);
            if ($ok && \function_exists('fsync')) {
                fsync($fh);
            }
        } finally {
            restore_error_handler();
        }
        fclose($fh);
        if (! $ok) {
            throw new RuntimeException('upgrade journal write failed'.($osError !== '' ? ': '.$osError : ''));
        }
    }

    /** @return list<array<string,mixed>> */
    public function events(): array
    {
        if (! $this->isSafe()) {
            throw new RuntimeException(self::UNSAFE); // a forged journal must never drive recovery
        }
        if (! file_exists($this->file)) {
            return [];
        }
        if (! is_file($this->file)) {
            throw new RuntimeException(self::UNREADABLE); // something else at the journal path is never "no events"
        }
        $fh = $this->open('rb');
        $raw = (string) stream_get_contents($fh);
        fclose($fh);
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (\is_array($row)) {
                $out[] = $row; // a torn final line (crash mid-write) is ignored, never fatal
            }
        }

        return $out;
    }

    public function has(string $event): bool
    {
        foreach ($this->events() as $row) {
            if (($row['ev'] ?? null) === $event) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null the most recent record of any of $events */
    public function last(string ...$events): ?array
    {
        $found = null;
        foreach ($this->events() as $row) {
            if ($events === [] || \in_array($row['ev'] ?? null, $events, true)) {
                $found = $row;
            }
        }

        return $found;
    }
}
