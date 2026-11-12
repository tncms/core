<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP plugin readiness: the disposable `sm-consumer` plugin
 * (tests/Fixtures/SitemapConsumer) goes through the real lifecycle —
 * PluginLifecycleManager activation (its own migration), ExtensionManager boot
 * (provider + routes/web.php), deactivation — with Knowledge Library-, Lottery-
 * and Ecommerce-shaped URL families. Integration means its URLs are in Core's
 * index and child XML, not that a class was registered.
 */
final class PluginSitemapReadinessTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const SLUG = 'sm-consumer';

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        config(['cms.paths.plugins' => base_path('tests/Fixtures/SitemapConsumer')]);
        app('cms.extension')->flushRegistry();
        Cache::forget('smc.fail');
        Cache::forget('smc.hostile');
    }

    /** Activate through the lifecycle manager, then boot in real route order. */
    private function activate(): void
    {
        $result = app('cms.plugin_lifecycle')->activate(self::SLUG);
        $this->assertTrue($result->success, (string) $result->error);
        $this->boot();
    }

    private function boot(): void
    {
        $ext = app('cms.extension');
        app('router')->setRoutes(new RouteCollection);
        require base_path('packages/thenguyen/cms-core/routes/web.php');
        $ext->flushRegistry();
        $ext->bootActivePlugins();
        require base_path('packages/thenguyen/cms-core/routes/frontend.php');
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    private function seedFixture(): void
    {
        $this->ids = \Plugins\SmConsumer\Catalog::seedFamilies();
    }

    /** @return list<string> */
    private function pluginParts(): array
    {
        return array_values(array_filter($this->indexNames(), static fn (string $n): bool => str_starts_with($n, self::SLUG.'-')));
    }

    /** @return list<string> */
    private function pluginLocs(): array
    {
        $out = [];
        foreach ($this->pluginParts() as $name) {
            array_push($out, ...$this->chunkLocs($name));
        }

        return $out;
    }

    public function test_activation_lists_every_family_in_the_index_and_child_xml(): void
    {
        $this->activate();
        $this->seedFixture();

        $this->assertSame([
            'sm-consumer-ec_categories-vi-1', 'sm-consumer-ec_categories-en-1',
            'sm-consumer-ec_products-vi-1', 'sm-consumer-ec_products-en-1',
            'sm-consumer-kb_articles-vi-1', 'sm-consumer-kb_articles-en-1',
            'sm-consumer-kb_categories-vi-1', 'sm-consumer-kb_categories-en-1',
            'sm-consumer-lottery_draws-vi-1', 'sm-consumer-lottery_draws-en-1',
        ], $this->pluginParts());

        $this->assertSame(['http://localhost/kien-thuc/bai-viet/kien-thuc-co-ban', 'http://localhost/kien-thuc/bai-viet/chi-tieng-viet'], $this->chunkLocs('sm-consumer-kb_articles-vi-1'));
        $this->assertSame(['http://localhost/en/knowledge/articles/basic-knowledge'], $this->chunkLocs('sm-consumer-kb_articles-en-1'));
        $this->assertSame(['http://localhost/xo-so/mien-bac/01-11-2026.html', 'http://localhost/xo-so/mien-nam/02-11-2026.html'], $this->chunkLocs('sm-consumer-lottery_draws-vi-1'));
        $this->assertSame(['http://localhost/en/lottery-results/mien-bac/01-11-2026.html'], $this->chunkLocs('sm-consumer-lottery_draws-en-1'));
        $this->assertSame(['http://localhost/cua-hang/san-pham/ao-thun'], $this->chunkLocs('sm-consumer-ec_products-vi-1'));
        $this->assertSame(['http://localhost/en/shop/categories/fashion'], $this->chunkLocs('sm-consumer-ec_categories-en-1'));

        $locs = $this->pluginLocs();
        foreach (['ban-nhap-bi-mat', 'secret-draft', 'rieng-tu-bi-mat', 'da-xoa', 'deleted'] as $hidden) {
            foreach ($locs as $loc) {
                $this->assertStringNotContainsString($hidden, $loc);
            }
        }

        // Every listed URL is served by the plugin itself (200), in its locale.
        foreach ($locs as $loc) {
            $this->assertStringStartsWith('SMC|', (string) $this->get($loc)->assertOk()->getContent(), $loc);
        }
        $this->get('/kien-thuc/bai-viet/ban-nhap-bi-mat')->assertNotFound();
    }

    public function test_alternates_are_reciprocal_with_x_default(): void
    {
        $this->activate();
        $this->seedFixture();

        $xml = $this->get('/sitemaps/sm-consumer-kb_articles-en-1.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('hreflang="vi" href="http://localhost/kien-thuc/bai-viet/kien-thuc-co-ban"', $xml);
        $this->assertStringContainsString('hreflang="en" href="http://localhost/en/knowledge/articles/basic-knowledge"', $xml);
        $this->assertStringContainsString('hreflang="x-default" href="http://localhost/kien-thuc/bai-viet/kien-thuc-co-ban"', $xml);

        $vi = $this->get('/sitemaps/sm-consumer-kb_articles-vi-1.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('hreflang="en" href="http://localhost/en/knowledge/articles/basic-knowledge"', $vi);
        // A vi-only item carries no alternates.
        $this->assertSame(1, substr_count($vi, 'hreflang="en"'));
    }

    public function test_deactivation_removes_the_urls_and_reactivation_restores_them(): void
    {
        $this->activate();
        $this->seedFixture();
        $this->assertNotSame([], $this->pluginParts());

        $this->assertTrue(app('cms.plugin_lifecycle')->deactivate(self::SLUG));

        $this->assertSame([], $this->pluginParts(), 'index no longer references the plugin');
        $this->get('/sitemaps/sm-consumer-kb_articles-vi-1.xml')->assertNotFound();

        $this->activate();
        $this->assertContains('sm-consumer-kb_articles-vi-1', $this->pluginParts());
        $this->assertContains('http://localhost/kien-thuc/bai-viet/kien-thuc-co-ban', $this->pluginLocs());
    }

    public function test_a_provider_larger_than_the_chunk_size_is_split_exactly(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $this->activate();
        $this->seedFixture();
        \Plugins\SmConsumer\Catalog::seedProducts(1200, englishEvery: 3);

        $vi = array_values(array_filter($this->pluginParts(), static fn ($n) => str_starts_with($n, 'sm-consumer-ec_products-vi-')));
        $en = array_values(array_filter($this->pluginParts(), static fn ($n) => str_starts_with($n, 'sm-consumer-ec_products-en-')));
        $this->assertSame(['sm-consumer-ec_products-vi-1', 'sm-consumer-ec_products-vi-2', 'sm-consumer-ec_products-vi-3'], $vi);
        $this->assertSame(['sm-consumer-ec_products-en-1'], $en);

        $all = [];
        foreach ($vi as $name) {
            $locs = $this->chunkLocs($name);
            $this->assertLessThanOrEqual(500, count($locs));
            array_push($all, ...$locs);
        }
        $this->assertCount(1201, $all, 'every product exactly once');
        $this->assertSame($all, array_values(array_unique($all)));
        $this->assertCount(401, $this->chunkLocs('sm-consumer-ec_products-en-1'));
    }

    public function test_plugin_writes_refresh_only_its_own_manifest(): void
    {
        $this->activate();
        $this->seedFixture();
        $this->pluginParts();
        $core = Cache::get('cms.sitemap.version.core');
        $own = Cache::get('cms.sitemap.version.'.self::SLUG);

        $topicOnlyVi = \Plugins\SmConsumer\Catalog::create(['family' => 'kb_category', 'slug_vi' => 'dia-ly']);

        $this->assertSame($core, Cache::get('cms.sitemap.version.core'));
        $this->assertNotSame($own, Cache::get('cms.sitemap.version.'.self::SLUG));
        $this->assertContains('http://localhost/kien-thuc/chu-de/dia-ly', $this->pluginLocs());

        \Plugins\SmConsumer\Catalog::update($topicOnlyVi->id, ['status' => 'draft']);
        $this->assertNotContains('http://localhost/kien-thuc/chu-de/dia-ly', $this->pluginLocs());
    }

    public function test_a_failing_provider_set_is_isolated_and_leaks_nothing(): void
    {
        $this->activate();
        $this->seedFixture();
        $this->pluginParts();
        Cache::forever('smc.fail', 'ec_categories');

        $failed = $this->get('/sitemaps/sm-consumer-ec_categories-vi-1.xml');
        $failed->assertStatus(503)->assertHeader('Retry-After', '300');
        $this->assertStringNotContainsString('.env', (string) $failed->getContent());
        $this->assertStringNotContainsString('fixture failure', (string) $failed->getContent());

        $this->get('/sitemaps/sm-consumer-kb_articles-vi-1.xml')->assertOk();
        $this->get('/sitemaps/core-home-vi-1.xml')->assertOk();
        $this->get('/sitemap.xml')->assertOk();
    }

    public function test_hostile_urls_are_refused_and_leave_no_part(): void
    {
        $this->activate();
        $this->seedFixture();
        Cache::forever('smc.hostile', true);
        \Plugins\SmConsumer\Catalog::update($this->ids['article'], []); // invalidate own manifest
        Log::spy();

        $names = $this->indexNames();
        $this->assertNotContains('sm-consumer-hostile-vi-1', $names);
        $this->assertNotContains('sm-consumer-hostile-en-1', $names);

        $all = $this->allLocs();
        foreach ($all as $loc) {
            $this->assertMatchesRegularExpression('#^http://localhost(/|$)#', $loc);
            foreach (['evil.example', '/blog/stolen', '/admin', '/account', 'signature=', 'token=', '..'] as $bad) {
                $this->assertStringNotContainsString($bad, $loc);
            }
        }
        $this->assertSame($all, array_values(array_unique($all)), 'no duplicate canonical URL across Core and the plugin');
    }

    public function test_signed_or_token_urls_are_refused_but_ordinary_queries_are_not(): void
    {
        $validator = new \TheNguyen\CMS\Sitemap\SitemapUrlValidator(app('router'), 'http://localhost', ['vi', 'en'], 'vi', false);
        $this->assertFalse($validator->valid('http://localhost/blog/x?signature=abc', 'vi', null));
        $this->assertFalse($validator->valid('http://localhost/blog/x?expires=1&Signature=abc', 'vi', null));
        $this->assertFalse($validator->valid('http://localhost/blog/x?token=abc', 'vi', null));
        $this->assertFalse($validator->valid('http://localhost/blog/x?a=1&access_token=abc', 'vi', null));
        $this->assertTrue($validator->valid('http://localhost/blog/x?page=2', 'vi', null));
        $this->assertTrue($validator->valid('http://localhost/blog/x?tokens=list', 'vi', null));
    }

    public function test_plugin_sets_need_no_core_content(): void
    {
        $this->activate();
        $this->seedFixture();

        // No Core posts, pages or terms exist: only the Core home sets remain.
        $core = array_values(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'core-')));
        $this->assertSame(['core-home-vi-1', 'core-home-en-1'], $core);
        $this->assertNotSame([], $this->pluginParts());
        $this->assertTrue(Route::has('smc.kb_articles.vi'));
    }
}
