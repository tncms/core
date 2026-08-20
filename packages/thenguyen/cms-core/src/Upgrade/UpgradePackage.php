<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;
use ZipArchive;

/**
 * Official Core-upgrade package: identity, verification and safe staging
 * (CORE-UPGRADE-1, §13/§14/§22).
 *
 * Wraps an uploaded/selected .zip stored in PROTECTED storage (never public/,
 * never executed from public/). It reads the two package manifests
 * (tncms-upgrade.json + tncms-core.manifest.json) directly from the archive to
 * verify product/type/version/checksum/ownership BEFORE any live mutation, and
 * extracts to protected staging with full path-traversal / absolute / drive /
 * symlink guards and post-extraction staged-checksum verification.
 *
 * This is the single archive-safety authority for the upgrade subsystem; its
 * limits are Core-package-scale (a full runtime payload with bundled vendor is
 * ~14k entries), distinct from the small plugin/theme installer limits.
 */
final class UpgradePackage
{
    /** Core payload is large (bundled vendor + built assets). */
    private const MAX_ENTRIES = 80_000;

    private const MAX_TOTAL_BYTES = 1_073_741_824; // 1 GiB extracted ceiling

    public const UPGRADE_MANIFEST = 'tncms-upgrade.json';

    public const CORE_MANIFEST = 'tncms-core.manifest.json';

    /** Payload files that MUST be present for a runtime-ready upgrade. */
    private const REQUIRED_PAYLOAD = [
        'vendor/autoload.php',
        'public/build/manifest.json',
        'artisan',
        'bootstrap/app.php',
        self::UPGRADE_MANIFEST,
        self::CORE_MANIFEST,
    ];

    public function __construct(private string $zipPath)
    {
        $this->zipPath = str_replace('\\', '/', $zipPath);
    }

    public function path(): string
    {
        return $this->zipPath;
    }

    public function sha256(): string
    {
        return is_file($this->zipPath) ? (string) hash_file('sha256', $this->zipPath) : '';
    }

    /** @return array<string,mixed>|null upgrade metadata JSON */
    public function upgradeManifest(): ?array
    {
        return $this->readJsonEntry(self::UPGRADE_MANIFEST);
    }

    /** @return array<string,mixed>|null ownership record JSON */
    public function coreManifest(): ?array
    {
        return $this->readJsonEntry(self::CORE_MANIFEST);
    }

    /**
     * Full pre-mutation verification (§14). Returns a structured result; the
     * FIRST failing reason drives the UI message. `ok` is true only when every
     * hard check passes: a tampered package, wrong product, downgrade or
     * out-of-range source is a hard failure.
     *
     * @return array{ok:bool, reason:string, target_version:?string, min_source:?string,
     *               has_migrations:bool, checks:array<int,array{name:string,ok:bool,detail:string}>}
     */
    public function verify(string $currentVersion): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        $result = static fn (bool $ok, string $reason, ?string $target, ?string $min, bool $mig) => [
            'ok' => $ok, 'reason' => $reason, 'target_version' => $target,
            'min_source' => $min, 'has_migrations' => $mig, 'checks' => $checks,
        ];

        if (! is_file($this->zipPath) || ! is_readable($this->zipPath)) {
            $add('archive:readable', false, $this->zipPath);

            return $result(false, 'The upgrade package could not be read.', null, null, false);
        }
        if (strtolower((string) pathinfo($this->zipPath, PATHINFO_EXTENSION)) !== 'zip') {
            $add('archive:is-zip', false, 'expected .zip');

            return $result(false, 'Only .zip upgrade packages are accepted.', null, null, false);
        }
        $add('archive:readable', true);

        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            $add('archive:opens', false);

            return $result(false, 'The upgrade package is not a valid archive.', null, null, false);
        }
        $add('archive:opens', true);

        $entryError = $this->validateEntries($zip);
        $add('archive:path-safe', $entryError === null, (string) $entryError);
        if ($entryError !== null) {
            $zip->close();

            return $result(false, $entryError, null, null, false);
        }

        // Required payload present as entries.
        foreach (self::REQUIRED_PAYLOAD as $rel) {
            $add("payload:$rel", $zip->locateName($rel) !== false, $rel);
        }

        $up = $this->decode($zip->getFromName(self::UPGRADE_MANIFEST));
        $core = $this->decode($zip->getFromName(self::CORE_MANIFEST));
        $coreRaw = $zip->getFromName(self::CORE_MANIFEST);
        $zip->close();

        $add('manifest:upgrade-parses', \is_array($up), self::UPGRADE_MANIFEST);
        $add('manifest:core-parses', \is_array($core), self::CORE_MANIFEST);
        if (! \is_array($up) || ! \is_array($core) || ! \is_string($coreRaw)) {
            return $result(false, 'The upgrade package manifests are missing or corrupt.', null, null, false);
        }

        $target = isset($up['target_version']) ? (string) $up['target_version'] : null;
        $minSource = isset($up['minimum_supported_source_version']) ? (string) $up['minimum_supported_source_version'] : null;
        $hasMig = (bool) ($up['has_migrations'] ?? false);

        $productOk = ($up['product'] ?? null) === 'TNCMS';
        $add('manifest:product-tncms', $productOk, (string) ($up['product'] ?? ''));
        if (! $productOk) {
            return $result(false, 'This is not a TNCMS package.', $target, $minSource, $hasMig);
        }

        $typeOk = ($up['package_type'] ?? null) === 'core-upgrade';
        $add('manifest:type-core-upgrade', $typeOk, (string) ($up['package_type'] ?? ''));
        if (! $typeOk) {
            return $result(false, 'This is not a Core upgrade package (wrong package type).', $target, $minSource, $hasMig);
        }

        // Core-manifest checksum recorded in the upgrade manifest must match the
        // shipped core manifest bytes (tamper / corruption guard).
        $shaOk = hash_equals((string) ($up['core_manifest_sha256'] ?? ''), hash('sha256', $coreRaw));
        $add('manifest:core-checksum', $shaOk, 'core_manifest_sha256');
        if (! $shaOk) {
            return $result(false, 'The upgrade package failed its integrity checksum.', $target, $minSource, $hasMig);
        }

        // Ownership sanity.
        $ownershipOk = isset($core['ownership']['replace_dirs'], $core['ownership']['preserve_prefixes'])
            && \is_array($core['files'] ?? null) && \count($core['files']) > 0;
        $add('manifest:ownership-present', $ownershipOk, 'ownership + files');
        if (! $ownershipOk) {
            return $result(false, 'The upgrade package is missing its ownership record.', $target, $minSource, $hasMig);
        }

        // Versions.
        $targetOk = $target !== null && $target !== '';
        $add('version:target-present', $targetOk, (string) $target);
        if (! $targetOk) {
            return $result(false, 'The upgrade package has no target version.', $target, $minSource, $hasMig);
        }

        $notDowngrade = version_compare($target, $currentVersion, '>');
        $add('version:target-newer', $notDowngrade, "current=$currentVersion target=$target");
        if (! $notDowngrade) {
            return $result(false, "This package targets $target, which is not newer than the installed $currentVersion. Downgrades are not supported.", $target, $minSource, $hasMig);
        }

        $sourceSupported = $minSource === null || $minSource === '' || version_compare($currentVersion, $minSource, '>=');
        $add('version:source-supported', $sourceSupported, "current=$currentVersion min=$minSource");
        if (! $sourceSupported) {
            return $result(false, "This package requires at least $minSource, but the installed version is $currentVersion.", $target, $minSource, $hasMig);
        }

        return $result(true, 'ok', $target, $minSource, $hasMig);
    }

    /**
     * Safely extract the whole package to $stagingDir (emptied first), then
     * verify staged file checksums against the core manifest (§22). Never
     * extract from the raw archive during apply — only from verified staging.
     *
     * @return array{ok:bool, reason:string, files:int, verified:int}
     */
    public function stageTo(string $stagingDir): array
    {
        $stagingDir = rtrim(str_replace('\\', '/', $stagingDir), '/');
        $this->rrmdir($stagingDir);
        if (! @mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
            throw new RuntimeException("cannot create staging dir: $stagingDir");
        }

        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            return ['ok' => false, 'reason' => 'cannot open package', 'files' => 0, 'verified' => 0];
        }
        $entryError = $this->validateEntries($zip);
        if ($entryError !== null) {
            $zip->close();

            return ['ok' => false, 'reason' => $entryError, 'files' => 0, 'verified' => 0];
        }
        if (! $zip->extractTo($stagingDir)) {
            $zip->close();

            return ['ok' => false, 'reason' => 'extraction failed', 'files' => 0, 'verified' => 0];
        }
        $count = $zip->numFiles;
        $zip->close();

        // Reject any symlink that slipped through the archive (external attrs).
        if ($this->containsSymlink($stagingDir)) {
            return ['ok' => false, 'reason' => 'the package contains a symlink, which is not allowed', 'files' => $count, 'verified' => 0];
        }

        // Verify staged files against the shipped core manifest checksums.
        $core = $this->readJsonEntryFromDir($stagingDir, self::CORE_MANIFEST);
        $files = \is_array($core['files'] ?? null) ? $core['files'] : [];
        $verified = 0;
        foreach ($files as $rel => $sha) {
            $abs = $stagingDir.'/'.$rel;
            if (! is_file($abs) || ! hash_equals((string) $sha, (string) hash_file('sha256', $abs))) {
                return ['ok' => false, 'reason' => "staged checksum mismatch: $rel", 'files' => $count, 'verified' => $verified];
            }
            $verified++;
        }

        return ['ok' => true, 'reason' => 'ok', 'files' => $count, 'verified' => $verified];
    }

    // ---- internals ------------------------------------------------------

    /** Validate every entry for traversal/absolute/drive/limits BEFORE extract. */
    private function validateEntries(ZipArchive $zip): ?string
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            return 'The upgrade package contains too many files.';
        }
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                return 'The upgrade package contains an unreadable entry.';
            }
            $name = str_replace('\\', '/', (string) $stat['name']);
            if (trim($name) === '') {
                return 'The upgrade package contains an empty entry name.';
            }
            if (str_starts_with($name, '/') || preg_match('#^[a-zA-Z]:#', $name) === 1) {
                return 'The upgrade package contains an absolute path, which is not allowed.';
            }
            foreach (explode('/', $name) as $segment) {
                if ($segment === '..') {
                    return 'The upgrade package contains a path-traversal entry ("..").';
                }
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_TOTAL_BYTES) {
                return 'The upgrade package is too large to extract safely.';
            }
        }

        return null;
    }

    private function containsSymlink(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            if ($item->isLink()) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|null */
    private function readJsonEntry(string $entry): ?array
    {
        if (! is_file($this->zipPath)) {
            return null;
        }
        $zip = new ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            return null;
        }
        $raw = $zip->getFromName($entry);
        $zip->close();

        return $this->decode($raw);
    }

    /** @return array<string,mixed>|null */
    private function readJsonEntryFromDir(string $dir, string $entry): ?array
    {
        $file = $dir.'/'.$entry;

        return is_file($file) ? $this->decode((string) file_get_contents($file)) : null;
    }

    /** @return array<string,mixed>|null */
    private function decode(string|false $raw): ?array
    {
        if (! \is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null;
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
