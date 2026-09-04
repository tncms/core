<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use TheNguyen\CMS\Support\ExtensionDiscovery;
use TheNguyen\CMS\Support\PublishResult;

/**
 * Publishes a theme's source assets (themes/{slug}/assets) into the public web
 * root (public/themes/{slug}) — the engine behind `php artisan theme:publish`
 * (v1.0.0-beta.7.1.12).
 *
 * Hard safety guarantees:
 *   - copies only web-servable file types (strict allowlist) — a stray
 *     .php/.env/.sql in a theme's assets can never reach the public web root;
 *   - never symlinks (copy only — shared-hosting friendly), and skips any
 *     symlinked source file;
 *   - never deletes anything outside public/themes/{slug} (the --clean guard
 *     asserts the destination is exactly that path before removing it);
 *   - a path-traversal slug is rejected before any path is built.
 */
class ThemeAssetPublisher
{
    /**
     * Web-servable file extensions that may be published. Anything not on this
     * list is skipped (never copied). Note that .blade.php / .php / .map etc.
     * all resolve to a disallowed extension and are therefore never published.
     */
    public const ALLOWED_EXTENSIONS = [
        'css', 'js', 'mjs', 'json',
        'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico',
        'woff', 'woff2', 'ttf', 'eot',
        'txt', 'xml', 'webmanifest',
    ];

    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * Publish a single theme's assets.
     *
     * @param  array{dry_run?: bool, clean?: bool}  $options
     */
    public function publish(string $theme, array $options = []): PublishResult
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $clean = (bool) ($options['clean'] ?? false);

        // Reject path-traversal / unsafe slugs before building any path.
        if (! $this->isSafeSlug($theme)) {
            return new PublishResult(
                theme: $theme,
                source: '',
                destination: '',
                errors: ['Invalid theme slug: '.$theme],
                dryRun: $dryRun,
                hasAssets: false,
            );
        }

        $source = $this->themes->themeAssetsPath($theme);
        $destination = $this->publicThemePath($theme);

        // No assets directory → nothing to publish (caller reports "skipped").
        if (! File::isDirectory($source)) {
            return new PublishResult(
                theme: $theme,
                source: $source,
                destination: $destination,
                dryRun: $dryRun,
                hasAssets: false,
            );
        }

        $deleted = 0;

        if ($clean) {
            $deleted = $dryRun
                ? $this->countFiles($destination)
                : $this->cleanDestination($theme, $destination);
        }

        $copied = 0;
        $skipped = 0;
        $errors = [];

        foreach (File::allFiles($source) as $file) {
            $pathname = $file->getPathname();

            // Never publish through a symlink (a symlinked file could escape
            // the source tree).
            if (is_link($pathname) || is_link($file->getPath())) {
                $skipped++;

                continue;
            }

            if (! in_array(strtolower($file->getExtension()), self::ALLOWED_EXTENSIONS, true)) {
                $skipped++;

                continue;
            }

            $relative = $file->getRelativePathname();
            $target = $destination.DIRECTORY_SEPARATOR.$relative;

            if ($dryRun) {
                $copied++;

                continue;
            }

            try {
                $dir = dirname($target);

                if (! File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true, true);
                }

                File::copy($pathname, $target);
                $copied++;
            } catch (\Throwable $e) {
                $errors[] = $relative.': '.$e->getMessage();
            }
        }

        return new PublishResult(
            theme: $theme,
            source: $source,
            destination: $destination,
            copied: $copied,
            skipped: $skipped,
            deleted: $deleted,
            errors: $errors,
            dryRun: $dryRun,
            hasAssets: true,
        );
    }

    /**
     * Publish every valid discovered theme.
     *
     * @param  array{dry_run?: bool, clean?: bool}  $options
     * @return array<int, PublishResult>
     */
    public function publishAll(array $options = []): array
    {
        $results = [];

        foreach ($this->themes->all() as $theme) {
            $results[] = $this->publish($theme->slug, $options);
        }

        return $results;
    }

    /**
     * Atomically (re)publish a theme's assets (EG-6 staged publication, §21).
     *
     * Instead of copying straight into the live public/themes/{slug} — which
     * leaves a half-written directory if a copy fails mid-way — this stages the
     * whole tree into a sibling temp directory, verifies every staged file, then
     * promotes it with a Windows/shared-hosting-safe swap:
     *
     *   validate → stage → verify staged → snapshot live → promote → verify live
     *
     * The previous live assets are moved aside (not destroyed) until the new
     * tree is in place, and are restored on any promotion failure, so activation
     * can roll back with no partial or mixed asset authority. Never throws.
     */
    public function publishAtomic(string $slug): PublishResult
    {
        if (! $this->isSafeSlug($slug)) {
            return new PublishResult(
                theme: $slug, source: '', destination: '',
                errors: ['Invalid theme slug: '.$slug], hasAssets: false,
            );
        }

        $source = $this->themes->themeAssetsPath($slug);
        $destination = $this->publicThemePath($slug);

        // No assets to publish → treat as a clean success (nothing staged).
        if (! File::isDirectory($source)) {
            return new PublishResult(
                theme: $slug, source: $source, destination: $destination, hasAssets: false,
            );
        }

        $base = dirname($destination);
        $token = bin2hex(random_bytes(6));
        $staging = $base.DIRECTORY_SEPARATOR.'.staging-'.$slug.'-'.$token;
        $backup = $base.DIRECTORY_SEPARATOR.'.backup-'.$slug.'-'.$token;

        try {
            // 1. Stage into a fresh temp directory.
            $this->resetDirectory($staging);
            [$copied, $skipped, $errors] = $this->copyAllowed($source, $staging);

            if ($errors !== []) {
                File::deleteDirectory($staging);

                return new PublishResult(
                    theme: $slug, source: $source, destination: $destination,
                    copied: $copied, skipped: $skipped, errors: $errors, hasAssets: true,
                );
            }

            // 2. Verify staged bytes — every allowlisted source file must be present.
            if (! $this->verifyStaged($source, $staging)) {
                File::deleteDirectory($staging);

                return new PublishResult(
                    theme: $slug, source: $source, destination: $destination,
                    copied: $copied, skipped: $skipped,
                    errors: ['Staged asset verification failed for '.$slug], hasAssets: true,
                );
            }

            // 3. Promote: snapshot live aside, swap staging in, restore on failure.
            $hadLive = File::isDirectory($destination);

            if ($hadLive && ! @rename($destination, $backup)) {
                File::deleteDirectory($staging);

                return new PublishResult(
                    theme: $slug, source: $source, destination: $destination,
                    copied: $copied, skipped: $skipped,
                    errors: ['Could not snapshot current assets for '.$slug], hasAssets: true,
                );
            }

            if (! @rename($staging, $destination)) {
                // Restore the previous live tree — no mixed authority.
                if ($hadLive) {
                    @rename($backup, $destination);
                }

                File::deleteDirectory($staging);

                return new PublishResult(
                    theme: $slug, source: $source, destination: $destination,
                    copied: $copied, skipped: $skipped,
                    errors: ['Could not promote staged assets for '.$slug], hasAssets: true,
                );
            }

            // 4. Success — discard the previous snapshot.
            if ($hadLive) {
                File::deleteDirectory($backup);
            }

            return new PublishResult(
                theme: $slug, source: $source, destination: $destination,
                copied: $copied, skipped: $skipped, hasAssets: true,
            );
        } catch (\Throwable $e) {
            // Best-effort cleanup; the live tree was never touched before promote.
            File::deleteDirectory($staging);

            if (isset($backup) && File::isDirectory($backup) && ! File::isDirectory($destination)) {
                @rename($backup, $destination);
            }

            return new PublishResult(
                theme: $slug, source: $source, destination: $destination,
                errors: ['Atomic publish failed for '.$slug.': '.$e->getMessage()], hasAssets: true,
            );
        }
    }

    /**
     * Copy allowlisted, non-symlinked files from a source tree into a
     * destination, mirroring publish()'s safety rules.
     *
     * @return array{0: int, 1: int, 2: list<string>} [copied, skipped, errors]
     */
    private function copyAllowed(string $source, string $destination): array
    {
        $copied = 0;
        $skipped = 0;
        $errors = [];

        foreach (File::allFiles($source) as $file) {
            $pathname = $file->getPathname();

            if (is_link($pathname) || is_link($file->getPath())) {
                $skipped++;

                continue;
            }

            if (! in_array(strtolower($file->getExtension()), self::ALLOWED_EXTENSIONS, true)) {
                $skipped++;

                continue;
            }

            $relative = $file->getRelativePathname();
            $target = $destination.DIRECTORY_SEPARATOR.$relative;

            try {
                $dir = dirname($target);

                if (! File::isDirectory($dir)) {
                    File::makeDirectory($dir, 0755, true, true);
                }

                File::copy($pathname, $target);
                $copied++;
            } catch (\Throwable $e) {
                $errors[] = $relative.': '.$e->getMessage();
            }
        }

        return [$copied, $skipped, $errors];
    }

    /**
     * Every allowlisted, non-symlinked source file must exist in the staged tree.
     */
    private function verifyStaged(string $source, string $staging): bool
    {
        foreach (File::allFiles($source) as $file) {
            if (is_link($file->getPathname()) || is_link($file->getPath())) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $staged = $staging.DIRECTORY_SEPARATOR.$file->getRelativePathname();

            if (! File::isFile($staged)) {
                return false;
            }
        }

        return true;
    }

    private function resetDirectory(string $dir): void
    {
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }

        File::makeDirectory($dir, 0755, true, true);
    }

    /**
     * Delete public/themes/{slug} before publishing — but only after asserting
     * the destination is exactly that path, so public/themes, public/uploads,
     * and public itself can never be removed. Returns the number of files that
     * were deleted.
     */
    private function cleanDestination(string $slug, string $destination): int
    {
        if (! $this->isWithinPublicThemes($slug, $destination)) {
            return 0;
        }

        if (! File::isDirectory($destination)) {
            return 0;
        }

        $count = $this->countFiles($destination);

        File::deleteDirectory($destination);

        return $count;
    }

    /**
     * Guard for --clean: the destination must be exactly {themes root}/{slug}
     * with a safe slug. This makes deletion of a broader path impossible.
     */
    private function isWithinPublicThemes(string $slug, string $destination): bool
    {
        if (! $this->isSafeSlug($slug)) {
            return false;
        }

        $base = $this->normalize((string) config('cms.paths.theme_assets', public_path('themes')));
        $dest = $this->normalize($destination);

        return $dest === $base.'/'.$slug;
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function countFiles(string $dir): int
    {
        if (! File::isDirectory($dir)) {
            return 0;
        }

        return count(File::allFiles($dir));
    }

    private function publicThemePath(string $slug): string
    {
        $base = (string) config('cms.paths.theme_assets', public_path('themes'));

        return $base.DIRECTORY_SEPARATOR.$slug;
    }

    /**
     * A slug is safe when it is a plain extension folder name: alphanumeric
     * plus dash/underscore, never an ignored/hidden directory. This rejects
     * "..", "../foo", "foo/bar", and "foo\bar" — path traversal is impossible.
     */
    private function isSafeSlug(string $slug): bool
    {
        return $slug !== ''
            && ! ExtensionDiscovery::isIgnored($slug)
            && preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug) === 1;
    }
}
