<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * Ephemeral pre-install bootstrap key (CORE-INSTALLER-2).
 *
 * A fresh shared-hosting extract ships no .env and an empty APP_KEY, so cookie /
 * session encryption would throw MissingAppKeyException on the very first
 * request — before the wizard can render. This mints a cryptographically secure
 * key ONCE and keeps it in a dedicated file (storage/framework/tncms-installer.key)
 * so it is stable across every pre-install wizard request.
 *
 * It is NOT the site's permanent APP_KEY: it is never written to .env, never
 * logged/rendered/reported, and is removed the moment the permanent .env has
 * been committed AND the runtime has switched to the permanent key
 * (InstallerManager::finalizeKeyTransition). An installed site never depends on
 * it, and it is forbidden from every distribution artifact (verify.php).
 */
class InstallerBootstrapKey
{
    private const FILE = 'framework'.DIRECTORY_SEPARATOR.'tncms-installer.key';

    public function path(): string
    {
        return storage_path(self::FILE);
    }

    /**
     * Return the stable ephemeral key, minting + persisting it on first use.
     * A Laravel-compatible "base64:" key so config('app.key') accepts it directly.
     */
    public function resolve(): string
    {
        $existing = $this->peek();

        if ($existing !== '') {
            return $existing;
        }

        $key = 'base64:'.base64_encode(random_bytes(32));

        $dir = \dirname($this->path());
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($this->path(), $key);
        @chmod($this->path(), 0600); // Best-effort.

        return $key;
    }

    /**
     * Read the stored ephemeral key WITHOUT minting one. Empty string when absent.
     */
    public function peek(): string
    {
        $path = $this->path();

        return is_file($path) ? trim((string) @file_get_contents($path)) : '';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * Remove the ephemeral key. Called only after the permanent .env is committed
     * and the runtime holds the permanent APP_KEY.
     */
    public function forget(): void
    {
        $path = $this->path();

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
