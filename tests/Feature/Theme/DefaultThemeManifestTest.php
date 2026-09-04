<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Tests\TestCase;
use TheNguyen\CMS\Services\AssetRegistry;

/**
 * B4 — the bundled Default theme adopts the declarative asset manifest
 * (EG-6, v1.0.0-beta.7.1.24). Default no longer hard-codes its own asset tags;
 * it declares them in theme.json and renders them through the Asset Registry in
 * dependency order. This guards that migration.
 */
final class DefaultThemeManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Read the bundled default theme from THIS worktree (the checkout under
        // certification), not whatever base_path() resolves to in the harness.
        config(['cms.paths.themes' => dirname(__DIR__, 3).'/themes']);
        app('cms.theme')->flushRegistry();
    }

    public function test_default_manifest_is_valid_with_single_primary(): void
    {
        $manifest = app('cms.theme_assets')->resolve('default');

        $this->assertTrue($manifest->isValid(), implode(' | ', $manifest->errors));
        $this->assertSame('default-app', $manifest->primaryHandle());
    }

    public function test_default_styles_render_in_dependency_order(): void
    {
        /** @var AssetRegistry $registry */
        $registry = app('cms.assets');
        $registry->flush();

        app('cms.theme_assets')->resolve('default')->applyTo($registry);

        $head = $registry->renderFrontendStyles();

        foreach (['css/tokens.css', 'css/app.css', 'css/sections.css'] as $css) {
            $this->assertStringContainsString('/themes/default/'.$css, $head);
        }

        // tokens before app before sections (declared deps on tokens).
        $tokens = strpos($head, '/themes/default/css/tokens.css');
        $app = strpos($head, '/themes/default/css/app.css');
        $sections = strpos($head, '/themes/default/css/sections.css');
        $this->assertLessThan($app, $tokens);
        $this->assertLessThan($sections, $app);
    }

    public function test_default_scripts_render_deferred_in_footer(): void
    {
        /** @var AssetRegistry $registry */
        $registry = app('cms.assets');
        $registry->flush();

        app('cms.theme_assets')->resolve('default')->applyTo($registry);

        $footer = $registry->renderFrontendScripts();

        $this->assertStringContainsString('/themes/default/js/app.js', $footer);
        $this->assertStringContainsString('/themes/default/js/accordion.js', $footer);
        $this->assertStringContainsString('defer', $footer);
    }
}
