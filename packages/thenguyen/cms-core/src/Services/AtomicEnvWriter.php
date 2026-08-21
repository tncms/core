<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use RuntimeException;

/**
 * Atomic .env writer (CORE-INSTALLER-2).
 *
 * Writes environment content by the temp-file → flush → validate → rename
 * pattern so a permanent .env is created in ONE atomic move. A failure at any
 * point leaves NO half-written, secret-bearing .env: either the file is absent
 * (fresh commit) or a pre-existing valid .env is preserved untouched.
 *
 * The single filesystem move is the commit point. On a POSIX filesystem
 * rename() over an absent target is atomic; when a previous .env exists (a
 * resume after a partial install) the previous file is moved aside first and
 * restored on any failure, so the site is never left without a readable .env.
 */
class AtomicEnvWriter
{
    /**
     * Atomically write $contents to $path. Never leaves a partial secret file.
     *
     * @throws RuntimeException on any failure (temp write, validation, or move).
     */
    public function write(string $path, string $contents): void
    {
        if (trim($contents) === '') {
            throw new RuntimeException('Refusing to write an empty environment file.');
        }

        $dir = \dirname($path);

        if (! is_dir($dir) || ! is_writable($dir)) {
            throw new RuntimeException('The environment directory is not writable.');
        }

        // Same-directory temp so the finalizing rename never crosses a filesystem
        // boundary (which would make it a non-atomic copy).
        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open a temporary environment file.');
        }

        try {
            if (@fwrite($handle, $contents) !== \strlen($contents)) {
                throw new RuntimeException('Could not write the temporary environment file.');
            }
            @fflush($handle);
        } finally {
            @fclose($handle);
        }

        // Validate the fully-written temp file before it can become authoritative.
        if (@file_get_contents($tmp) !== $contents) {
            @unlink($tmp);
            throw new RuntimeException('The environment file failed post-write validation.');
        }

        $this->move($tmp, $path);

        @chmod($path, 0600); // Best-effort; shared hosting may ignore it (§16).
    }

    /**
     * Move the validated temp file into place. Extracted so tests can inject a
     * move failure before the rename (§42) via a subclass — not a production
     * bypass.
     *
     * @throws RuntimeException
     */
    protected function move(string $tmp, string $path): void
    {
        if (! is_file($path)) {
            if (! @rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Could not finalize the environment file.');
            }

            return;
        }

        // Resume: a previous valid .env exists. Preserve it until the replace
        // succeeds so a failed move never leaves the site without a .env.
        $backup = $path.'.bak';
        @unlink($backup);

        if (! @rename($path, $backup)) {
            @unlink($tmp);
            throw new RuntimeException('Could not stage the existing environment file.');
        }

        if (! @rename($tmp, $path)) {
            @rename($backup, $path); // Restore the previous .env.
            @unlink($tmp);
            throw new RuntimeException('Could not finalize the environment file.');
        }

        @unlink($backup);
    }
}
