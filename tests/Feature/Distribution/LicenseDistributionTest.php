<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CORE-PUBLISH-1 — the MIT LICENSE must ship in every Core distribution profile
 * and be enforced by the framework-free verifier.
 *
 * Drives manifest.php + verify.php directly (no build) to lock two guarantees:
 *   1. every profile's include_files stages the root LICENSE;
 *   2. the verifier HARD-FAILS an export that lacks LICENSE, and passes with it.
 */
final class LicenseDistributionTest extends TestCase
{
    private const TOOLS = 'tools/distribution';

    #[Test]
    public function every_profile_ships_the_root_license(): void
    {
        $manifest = require base_path(self::TOOLS.'/manifest.php');

        foreach (['source', 'install', 'upgrade'] as $profile) {
            $this->assertContains(
                'LICENSE',
                $manifest['profiles'][$profile]['include_files'],
                "profile {$profile} must stage the root LICENSE",
            );
        }
    }

    #[Test]
    public function verifier_requires_license_in_the_export(): void
    {
        require_once base_path(self::TOOLS.'/verify.php');
        $manifest = require base_path(self::TOOLS.'/manifest.php');

        $without = $this->makeSourceTree(withLicense: false);
        $res = tncms_core_verify($without, $manifest, 'source', $this->coreVersion());
        $this->assertFalse($res['pass'], 'Source export without LICENSE must fail verification.');
        $this->assertFalse($this->check($res, 'required:LICENSE'), 'required:LICENSE must fail when absent.');

        $with = $this->makeSourceTree(withLicense: true);
        $res2 = tncms_core_verify($with, $manifest, 'source', $this->coreVersion());
        $this->assertTrue($this->check($res2, 'required:LICENSE'), 'required:LICENSE must pass when present.');
    }

    /** @param array{checks:array<int,array{name:string,ok:bool}>} $res */
    private function check(array $res, string $name): ?bool
    {
        foreach ($res['checks'] as $c) {
            if ($c['name'] === $name) {
                return $c['ok'];
            }
        }

        return null;
    }

    private function coreVersion(): string
    {
        return \TheNguyen\CMS\Support\CmsInfo::VERSION;
    }

    private function makeSourceTree(bool $withLicense): string
    {
        $root = sys_get_temp_dir().'/tncms-lic-'.($withLicense ? 'ok' : 'missing').'-'.uniqid();
        $put = static function (string $rel, string $body = '') use ($root): void {
            $path = $root.'/'.$rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $body);
        };

        $put('composer.json', '{}');
        $put('artisan');
        $put('bootstrap/providers.php', '<?php return [];');
        $put('packages/thenguyen/cms-core/src/Support/CmsInfo.php', "<?php const VERSION = '".$this->coreVersion()."';");
        $put('plugins/hello-world/plugin.json', '{}');
        $put('themes/default/theme.json', '{}');

        if ($withLicense) {
            $put('LICENSE', "MIT License\n");
        }

        return $root;
    }
}
