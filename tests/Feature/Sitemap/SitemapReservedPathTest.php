<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteSourcePolicy;
use TheNguyen\CMS\Services\PermalinkManager;

/**
 * CORE-SITEMAP-S01: `/robots.txt`, `/sitemap.xml` and `/sitemaps/*` belong to
 * Core. Content slugs, permalink bases, localized route segments and plugin
 * routes cannot claim them; stored collisions are reported, never rewritten.
 */
final class SitemapReservedPathTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    public function test_sitemaps_is_a_reserved_permalink_base(): void
    {
        $permalink = app('cms.permalink');
        $this->assertTrue($permalink->isReserved('sitemaps'));
        $this->assertTrue($permalink->isReserved('/Sitemaps/'));
        $this->assertTrue($permalink->isReserved('sitemap.xml'));
        $this->assertTrue($permalink->isReserved('robots.txt'));
        $this->assertFalse($permalink->isReserved('sitemap-guide'), 'unrelated namespaces stay valid');
        $this->assertFalse($permalink->isReserved('blog'));
    }

    public function test_new_content_and_translations_cannot_take_the_sitemaps_slug(): void
    {
        $page = $this->smPost('Sitemaps', true, [], 'page');
        $vi = $page->translations()->where('locale', 'vi')->value('slug');
        $this->assertSame('sitemaps-2', $vi);

        $en = app('cms.content')->update($page, [
            'locale' => 'en', 'title' => 'Sitemaps', 'slug' => 'sitemaps', 'content' => '<p>x</p>',
        ], null, \Tests\Support\ContentFixtures::context());
        $this->assertSame('sitemaps-2', $en->translations()->where('locale', 'en')->value('slug'));

        $other = $this->smPost('Huong dan sitemap', false, [], 'page');
        $edited = app('cms.content')->update($other, ['locale' => 'vi', 'slug' => 'sitemaps'], null, \Tests\Support\ContentFixtures::context());
        $this->assertNotSame('sitemaps', $edited->translations()->where('locale', 'vi')->value('slug'));

        $ok = $this->smPost('Sitemap guide', false, [], 'page');
        $this->assertSame('sitemap-guide', $ok->translations()->where('locale', 'vi')->value('slug'), 'unrelated slug unchanged');
    }

    public function test_a_localized_core_route_segment_cannot_claim_sitemaps(): void
    {
        $errors = app(RouteDictionaryManager::class)->validate(['category' => ['en' => 'sitemaps']]);
        $this->assertNotSame([], $errors);
        $this->assertSame([], app(RouteDictionaryManager::class)->validate(['category' => ['en' => 'categories']]));
        $this->assertContains('sitemaps', PluginRouteSourcePolicy::RESERVED_SEGMENTS);
    }

    public function test_stored_collisions_are_reported_not_rewritten(): void
    {
        $page = $this->smPost('Trang mau', false, [], 'page');
        DB::table('cms_content_translations')->where('content_id', $page->id)->update(['slug' => 'sitemaps']);
        DB::table('cms_slugs')->where('reference_id', $page->id)->update(['slug' => 'sitemaps', 'full_path' => 'sitemaps']);
        app('cms.settings')->set('permalink.tag_base', 'sitemaps');

        $report = app('cms.permalink')->reservedCollisions();

        $this->assertContains(['kind' => 'base', 'setting' => 'permalink.tag_base', 'value' => 'sitemaps'], $report);
        $this->assertContains(['kind' => 'slug', 'locale' => 'vi', 'value' => 'sitemaps', 'reference_type' => DB::table('cms_slugs')->where('reference_id', $page->id)->value('reference_type'), 'reference_id' => $page->id], $report);
        // Nothing was rewritten.
        $this->assertSame('sitemaps', DB::table('cms_content_translations')->where('content_id', $page->id)->value('slug'));
        $this->assertSame('sitemaps', settings('permalink.tag_base'));
        // Core keeps its endpoints.
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_a_plugin_route_cannot_shadow_core_sitemap_or_robots_endpoints(): void
    {
        $root = $this->smRoot().DIRECTORY_SEPARATOR.'route-shadow';
        File::deleteDirectory($root);
        File::ensureDirectoryExists($root.'/routes');
        File::put($root.'/plugin.json', (string) json_encode(['name' => 'Route Shadow', 'slug' => 'route-shadow', 'version' => '1.0.0', 'author' => 't']));
        File::put($root.'/routes/web.php', <<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('sitemap.xml', fn () => 'PLUGIN-INDEX');
Route::get('sitemaps/{name}.xml', fn () => 'PLUGIN-CHILD');
Route::get('robots.txt', fn () => 'PLUGIN-ROBOTS');
Route::get('sitemaps', fn () => 'PLUGIN-SITEMAPS');
Route::get('route-shadow/sitemap.xml', fn () => 'PLUGIN-OWN');
PHP);
        Log::spy();
        $ext = app('cms.extension');
        $ext->flushRegistry();
        $this->assertTrue($ext->activatePlugin('route-shadow'));
        $ext->flushRegistry();
        $ext->bootActivePlugins();
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertStringNotContainsString('PLUGIN', (string) $this->get('/sitemap.xml')->assertOk()->getContent());
        $this->assertStringNotContainsString('PLUGIN', (string) $this->get('/robots.txt')->assertOk()->getContent());
        $this->assertSame('cms.sitemap.chunk', app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/sitemaps/core-home-vi-1.xml'))->getName());
        $this->assertNotSame('PLUGIN-SITEMAPS', $this->get('/sitemaps')->getContent());
        // Only the reserved routes were refused; the plugin keeps its own namespace.
        $uris = array_map(static fn ($r) => $r->uri(), array_filter(
            app('router')->getRoutes()->getRoutes(),
            static fn ($r) => in_array('cms.plugin-active:route-shadow', (array) $r->middleware(), true),
        ));
        $this->assertSame(['route-shadow/sitemap.xml'], array_values($uris));
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $m): bool => str_contains($m, 'route-shadow') && str_contains($m, 'reserved'))->atLeast()->once();
    }

    public function test_reserved_paths_constant_covers_all_core_seo_endpoints(): void
    {
        foreach (['robots.txt', 'sitemap.xml', 'sitemaps'] as $path) {
            $this->assertContains($path, PermalinkManager::RESERVED);
        }
        $this->assertNotNull(Str::of('x'));
    }
}
