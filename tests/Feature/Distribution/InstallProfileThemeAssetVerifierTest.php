<?php

declare(strict_types=1);

namespace Tests\Feature\Distribution;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CORE-DIST-1-H2 — regression guard for the real install-asset defect.
 *
 * H1 shipped an install ZIP whose pages returned 200 while the default theme's
 * published CSS/JS (tokens/app/sections.css, app/accordion.js served via
 * theme_asset() as /themes/default/...) 404'd — because the install build never
 * ran `theme:publish` and the verifier only checked the Vite public/build bundle.
 *
 * This test drives the framework-free distribution verifier directly against a
 * synthetic install tree and asserts it now HARD-FAILS when the published theme
 * assets are absent, and PASSES that specific check when they are present. It
 * locks the certification blind spot: "page 200 + Vite manifest present" is no
 * longer mistaken for "all required assets shipped".
 */
final class InstallProfileThemeAssetVerifierTest extends TestCase
{
    private const TOOLS = 'tools/distribution';

    /** @var list<string> */
    private const THEME_ASSETS = [
        'public/themes/default/css/tokens.css',
        'public/themes/default/css/app.css',
        'public/themes/default/css/sections.css',
        'public/themes/default/js/app.js',
        'public/themes/default/js/accordion.js',
    ];

    #[Test]
    public function manifest_requires_the_published_default_theme_assets(): void
    {
        $manifest = require base_path(self::TOOLS.'/manifest.php');

        foreach (self::THEME_ASSETS as $asset) {
            $this->assertContains(
                $asset,
                $manifest['install_required'],
                "install_required must list published theme asset: {$asset}",
            );
        }

        $this->assertContains(
            'public/themes/default',
            $manifest['install_theme_asset_roots'] ?? [],
            'install_theme_asset_roots must name the default theme public root.',
        );
    }

    #[Test]
    public function verifier_fails_install_when_published_theme_assets_are_missing(): void
    {
        require_once base_path(self::TOOLS.'/verify.php');
        $manifest = require base_path(self::TOOLS.'/manifest.php');

        // Tree WITHOUT the published theme assets — the H1 shape.
        $missing = $this->makeInstallTree(withThemeAssets: false);
        $res = tncms_core_verify($missing, $manifest, 'install', $this->coreVersion());

        $this->assertFalse($res['pass'], 'Install verification must FAIL with no published theme assets.');
        $this->assertFalse(
            $this->check($res, 'install:theme-assets-published:public/themes/default'),
            'The theme-asset root check must fail when public/themes/default is empty.',
        );
        $this->assertFalse(
            $this->check($res, 'required:public/themes/default/css/app.css'),
            'Each published theme asset must be a required path.',
        );

        // Same tree WITH the published theme assets — the H2 shape.
        $present = $this->makeInstallTree(withThemeAssets: true);
        $res2 = tncms_core_verify($present, $manifest, 'install', $this->coreVersion());

        $this->assertTrue(
            $this->check($res2, 'install:theme-assets-published:public/themes/default'),
            'The theme-asset root check must pass once assets are published.',
        );
        foreach (self::THEME_ASSETS as $asset) {
            $this->assertTrue(
                $this->check($res2, "required:{$asset}"),
                "Published theme asset must satisfy its required check: {$asset}",
            );
        }
    }

    /**
     * Read the outcome of a named verifier check.
     *
     * @param  array{checks:array<int,array{name:string,ok:bool}>}  $res
     */
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

    /**
     * Build the minimal install tree the verifier walks — every required path
     * present EXCEPT (optionally) the published theme assets under public/themes.
     */
    private function makeInstallTree(bool $withThemeAssets): string
    {
        $root = sys_get_temp_dir().'/tncms-h2-'.($withThemeAssets ? 'ok' : 'missing').'-'.uniqid();
        $put = static function (string $rel, string $body = '') use ($root): void {
            $path = $root.'/'.$rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $body);
        };

        // Core identity + install_required (minus theme assets).
        $put('composer.json', '{}');
        $put('composer.lock', '{}');
        $put('artisan');
        $put('bootstrap/app.php', '<?php');
        $put('bootstrap/providers.php', '<?php return [];');
        $put('.env.example');
        $put('vendor/autoload.php', '<?php');
        $put('public/build/manifest.json', '{}');
        $put(
            'packages/thenguyen/cms-core/src/Support/CmsInfo.php',
            "<?php const VERSION = '".$this->coreVersion()."';",
        );

        // Exactly one demo plugin + one default theme.
        $put('plugins/hello-world/plugin.json', '{}');
        $put('themes/default/theme.json', '{}');

        if ($withThemeAssets) {
            foreach (self::THEME_ASSETS as $asset) {
                $put($asset, '/* built */');
            }
        }

        return $root;
    }
}
