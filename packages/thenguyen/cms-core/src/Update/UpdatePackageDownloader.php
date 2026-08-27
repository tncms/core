<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Secure package downloader (CORE-UPGRADE-2, §secure-storage).
 *
 * Downloads a discovered release asset into PRIVATE staging under
 * storage/app/updates/<id>/package.zip — never a public directory, never an
 * exposed or guessable URL. The {@see UpdateClient} enforces HTTPS + a hard byte
 * ceiling during transfer; this class owns the staging layout and cleanup rules
 * so partial or superseded downloads never accumulate.
 *
 * The download is NOT trusted after it lands: {@see UpdateVerifier} must prove
 * its SHA-256, size and archive manifest before it can become a handoff.
 */
final class UpdatePackageDownloader
{
    private string $root;

    public function __construct(private UpdateClient $client, ?string $storageRoot = null)
    {
        $base = $storageRoot ?? ((\function_exists('storage_path') ? storage_path('app/updates') : (getcwd().'/storage/app/updates')));
        $this->root = rtrim(str_replace('\\', '/', (string) $base), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function dir(string $id): string
    {
        return $this->root.'/'.$id;
    }

    public function packagePath(string $id): string
    {
        return $this->dir($id).'/package.zip';
    }

    /**
     * Download the release asset described by $m into fresh private staging.
     *
     * @return array{id:string, path:string, bytes:int}
     */
    public function download(UpdateManifest $m): array
    {
        $this->prune(3);
        $id = bin2hex(random_bytes(8));
        $dest = $this->packagePath($id);

        // Ceiling is the advertised exact size: the transfer is aborted the
        // moment it would exceed it; the exact match is proven by the verifier.
        $bytes = $this->client->download($m->downloadUrl, $dest, $m->size);

        return ['id' => $id, 'path' => $dest, 'bytes' => $bytes];
    }

    /** Remove one download's staging directory. */
    public function cleanup(string $id): void
    {
        $this->rrmdir($this->dir($id));
    }

    /** Keep only the $keep most-recent download directories. */
    public function prune(int $keep): void
    {
        if (! is_dir($this->root)) {
            return;
        }
        $dirs = [];
        foreach (scandir($this->root) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $this->root.'/'.$e;
            if (is_dir($p)) {
                $dirs[$p] = @filemtime($p) ?: 0;
            }
        }
        arsort($dirs);
        foreach (\array_slice(array_keys($dirs), max(0, $keep)) as $old) {
            $this->rrmdir($old);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            is_dir($p) && ! is_link($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
