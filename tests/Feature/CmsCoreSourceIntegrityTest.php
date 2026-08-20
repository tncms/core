<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PB-DIST-HYGIENE-3 — permanent fresh-clone guard for the Core extension/plugin platform.
 *
 * The repository must be coherent from TRACKED Git bytes alone: every critical Core extension/plugin
 * class, and the in-repo Core collaborators it requires, must be committed — never left runnable only
 * because an untracked file happens to exist on a developer machine (the defect HYGIENE-2/3 repaired).
 *
 * This guard asks Git for TRACKING status (ls-files) — it deliberately does NOT check disk existence,
 * so it fails in a fresh-clone-style environment if any required platform source is absent from Git.
 */
final class CmsCoreSourceIntegrityTest extends TestCase
{
    private const CORE = 'packages/thenguyen/cms-core/src/';

    /**
     * Curated authority list: the extension/plugin platform classes that MUST be tracked for a fresh
     * clone to load the framework (path is repo-relative).
     *
     * @return list<array{0:string}>
     */
    public static function criticalPlatformSource(): array
    {
        return [
            [self::CORE.'Services/ExtensionInstaller.php'],
            [self::CORE.'Services/ExtensionManager.php'],
            [self::CORE.'Services/ThemeManager.php'],
            [self::CORE.'Services/PluginAssetPublisher.php'],
            [self::CORE.'Support/InstallResult.php'],
            [self::CORE.'Support/ExtensionDiscovery.php'],
            [self::CORE.'Support/AssetPublishResult.php'],
            [self::CORE.'Support/Plugin.php'],
            [self::CORE.'Providers/CmsServiceProvider.php'],
        ];
    }

    #[DataProvider('criticalPlatformSource')]
    public function test_critical_platform_source_is_tracked(string $path): void
    {
        $this->assertTrue(
            $this->isTracked($path),
            "Fresh-clone coherence: required Core platform source must be committed to Git: {$path}",
        );
    }

    /**
     * The specific dependency chains the HYGIENE-3 mission named: a tracked consumer must never depend
     * on an untracked in-repo collaborator.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function dependencyChains(): array
    {
        return [
            // consumer (tracked) => required in-repo collaborator (must also be tracked)
            [self::CORE.'Services/ExtensionInstaller.php', self::CORE.'Support/InstallResult.php'],
            [self::CORE.'Services/ExtensionManager.php', self::CORE.'Support/ExtensionDiscovery.php'],
            [self::CORE.'Services/ThemeManager.php', self::CORE.'Support/ExtensionDiscovery.php'],
            [self::CORE.'Services/PluginAssetPublisher.php', self::CORE.'Support/AssetPublishResult.php'],
            [self::CORE.'Services/ExtensionManager.php', self::CORE.'Support/Plugin.php'],
        ];
    }

    #[DataProvider('dependencyChains')]
    public function test_tracked_consumer_resolves_collaborator_from_git(string $consumer, string $collaborator): void
    {
        if (! $this->isTracked($consumer)) {
            $this->markTestSkipped("Consumer not tracked (covered elsewhere): {$consumer}");
        }
        $this->assertTrue(
            $this->isTracked($collaborator),
            "Tracked {$consumer} requires {$collaborator}, which must also be tracked for fresh-clone coherence.",
        );
    }

    public function test_no_required_platform_source_missing_from_git(): void
    {
        $missing = [];
        foreach (self::criticalPlatformSource() as [$path]) {
            if (! $this->isTracked($path)) {
                $missing[] = $path;
            }
        }
        $this->assertSame([], $missing, 'Required Core platform source missing from Git: '.implode(', ', $missing));
    }

    public function test_adopted_core_source_stays_generic(): void
    {
        // HYGIENE-3 adopts platform source only; it must never carry a plugin-specific special case.
        foreach ([self::CORE.'Support/ExtensionDiscovery.php', self::CORE.'Support/InstallResult.php'] as $rel) {
            $abs = base_path($rel);
            if (! is_file($abs)) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/page-?builder/i',
                (string) file_get_contents($abs),
                "Adopted Core source must stay generic (no plugin special case): {$rel}",
            );
        }
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function isTracked(string $path): bool
    {
        $root = base_path();
        if (! is_dir($root.'/.git')) {
            $this->markTestSkipped('Not a git work tree.');
        }
        $null = \DIRECTORY_SEPARATOR === '\\' ? '2>NUL' : '2>/dev/null';
        $cmd = 'git -C '.escapeshellarg($root).' ls-files --error-unmatch '.escapeshellarg($path).' '.$null;
        exec($cmd, $out, $code);

        return $code === 0;
    }
}
