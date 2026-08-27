<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Durable update state (CORE-UPGRADE-2, §persistence).
 *
 * Persists ONLY the non-sensitive facts an operator needs between visits: the
 * last discovery check, the last download and the last verification. It never
 * stores a token, secret, credential or Authorization header, and never any
 * telemetry beyond these states. The store lives in private storage.
 */
final class UpdateStateStore
{
    private string $file;

    public function __construct(?string $storageRoot = null)
    {
        $base = $storageRoot ?? ((\function_exists('storage_path') ? storage_path('app/updates') : (getcwd().'/storage/app/updates')));
        $root = rtrim(str_replace('\\', '/', (string) $base), '/');
        $this->file = $root.'/state.json';
    }

    public function file(): string
    {
        return $this->file;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        if (! is_file($this->file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($this->file), true);

        return \is_array($data) ? $data : [];
    }

    /** Record the outcome of a discovery check (redacted, no secrets). */
    public function recordCheck(UpdateAvailability $a): void
    {
        $this->patch(['last_check' => $a->toArray() + ['at' => gmdate('Y-m-d\TH:i:s\Z')]]);
    }

    /** @param array<string,mixed> $summary */
    public function recordDownload(array $summary): void
    {
        $this->patch(['last_download' => $summary + ['at' => gmdate('Y-m-d\TH:i:s\Z')]]);
    }

    /** @param array<string,mixed> $summary */
    public function recordVerification(array $summary): void
    {
        $this->patch(['last_verification' => $summary + ['at' => gmdate('Y-m-d\TH:i:s\Z')]]);
    }

    /** @param array<string,mixed> $patch */
    private function patch(array $patch): void
    {
        $dir = \dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $data = array_merge($this->all(), $patch);
        @file_put_contents($this->file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
