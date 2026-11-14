<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-10: a theme ZIP overwrite replaced themes/{slug} but never republished
 * public/themes/{slug}. That is harmless for an inactive theme (activation publishes atomically) and the active
 * theme cannot be overwritten — but the PARENT of the active child theme could be: its new views went live at
 * once against stale public assets (mixed asset authority). Overwriting a theme the active theme inherits from
 * is now refused exactly like overwriting the active theme itself, and like deleting that parent already is.
 */
final class ThemeParentOverwriteTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/p0-10-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/themes');
        File::ensureDirectoryExists($this->root.'/public-themes');
        config(['cms.paths.themes' => $this->root.'/themes', 'cms.paths.theme_assets' => $this->root.'/public-themes']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function themeZip(string $slug, string $version, ?string $parent = null, string $css = 'body{}'): string
    {
        $manifest = ['name' => ucfirst($slug), 'slug' => $slug, 'version' => $version, 'author' => 'x'] + ($parent ? ['parent' => $parent] : []);
        $zip = $this->root.'/'.$slug.'-'.$version.'.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString($slug.'/theme.json', json_encode($manifest));
        $archive->addFromString($slug.'/assets/site.css', $css);
        $archive->addFromString($slug.'/views/layouts/master.blade.php', '<html>@yield("content")</html>');
        $archive->close();

        return $zip;
    }

    private function activateWithoutPublishing(string $slug): void
    {
        settings()->set('theme.active', $slug, 'string', ['is_public' => true, 'autoload' => true]);
        settings()->clearCache();
        app('cms.theme')->flushRegistry();
    }

    public function test_the_parent_of_the_active_theme_cannot_be_overwritten(): void
    {
        $installer = app('cms.extension_installer');
        $this->assertTrue($installer->installThemeFromZip($this->themeZip('base', '1.0.0', css: 'v1'))->success);
        $this->assertTrue($installer->installThemeFromZip($this->themeZip('kid', '1.0.0', parent: 'base'))->success);
        $this->activateWithoutPublishing('kid');
        $this->assertSame(['kid'], app('cms.theme')->activeDependentsOf('base'));

        $result = $installer->installThemeFromZip($this->themeZip('base', '2.0.0', css: 'v2'), true);

        $this->assertFalse($result->success, 'overwriting a live parent must be refused');
        $this->assertStringContainsString('Switch themes before overwriting', (string) $result->message);
        $this->assertSame('v1', File::get($this->root.'/themes/base/assets/site.css'), 'the live parent is untouched');
    }

    public function test_an_unrelated_inactive_theme_can_still_be_overwritten(): void
    {
        $installer = app('cms.extension_installer');
        $this->assertTrue($installer->installThemeFromZip($this->themeZip('base', '1.0.0', css: 'v1'))->success);
        $this->assertTrue($installer->installThemeFromZip($this->themeZip('kid', '1.0.0', parent: 'base'))->success);
        $this->assertTrue($installer->installThemeFromZip($this->themeZip('other', '1.0.0'))->success);
        $this->activateWithoutPublishing('kid');

        $this->assertTrue($installer->installThemeFromZip($this->themeZip('other', '2.0.0', css: 'v2'), true)->success);
        $this->assertSame('v2', File::get($this->root.'/themes/other/assets/site.css'));
    }
}
