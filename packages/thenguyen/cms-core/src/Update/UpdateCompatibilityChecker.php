<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * Metadata-level compatibility gate (CORE-UPGRADE-2, §compatibility).
 *
 * Screens a discovered {@see UpdateManifest} against the running environment
 * BEFORE any download, using only the values the operator can trust after they
 * are re-proven on the archive itself later:
 *
 *   - target is strictly newer than the installed version (no downgrade),
 *   - the installed version meets the release's minimum_supported_version,
 *   - the running PHP satisfies the release's php_requirement,
 *   - the package is a Core upgrade package.
 *
 * This is deliberately distinct from {@see \TheNguyen\CMS\Upgrade\UpgradePackage}:
 * that class proves the same version rules against the *archive* manifest at
 * apply-authority level; this one screens the *feed* metadata so an incompatible
 * release is never even downloaded. PHP-requirement checking lives ONLY here.
 *
 * Pure and dependency-free (PHP version is injectable for tests).
 */
final class UpdateCompatibilityChecker
{
    public function __construct(private string $phpVersion = PHP_VERSION) {}

    /**
     * @return array{ok:bool, reason:string, detail:string,
     *               checks:array<int,array{name:string,ok:bool,detail:string}>}
     */
    public function check(UpdateManifest $m, string $currentVersion): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };
        $fail = static fn (string $reason, string $detail) => [
            'ok' => false, 'reason' => $reason, 'detail' => $detail, 'checks' => $checks,
        ];

        $typeOk = $m->packageType === UpdateManifest::PACKAGE_TYPE;
        $add('package-type', $typeOk, $m->packageType);
        if (! $typeOk) {
            return $fail('package_type_invalid', 'The release is not a Core upgrade package.');
        }

        $newer = version_compare($m->version, $currentVersion, '>');
        $add('target-newer', $newer, "current={$currentVersion} target={$m->version}");
        if (! $newer) {
            return $fail('not_newer', "The release {$m->version} is not newer than the installed {$currentVersion}.");
        }

        $min = $m->minimumSupportedVersion;
        $sourceOk = $min === null || version_compare($currentVersion, $min, '>=');
        $add('source-supported', $sourceOk, "current={$currentVersion} min=".(string) $min);
        if (! $sourceOk) {
            return $fail('source_unsupported', "This release requires at least {$min}; the installed version is {$currentVersion}.");
        }

        $php = $m->phpRequirement;
        $phpOk = $php === null || version_compare($this->phpVersion, $php, '>=');
        $add('php-requirement', $phpOk, "php={$this->phpVersion} required=".(string) $php);
        if (! $phpOk) {
            return $fail('php_unsupported', "This release requires PHP {$php}; the server runs {$this->phpVersion}.");
        }

        return ['ok' => true, 'reason' => 'ok', 'detail' => 'compatible', 'checks' => $checks];
    }
}
