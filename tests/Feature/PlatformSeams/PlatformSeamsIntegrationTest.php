<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

/**
 * CORE-PLATFORM-SEAMS-R1 Phase 3 — one plugin using every contract at once
 * (owned content, availability, theme-overridden views, dictionary segments)
 * through activation, deactivation and reactivation, with deterministic
 * results independent of plugin registration order.
 */
final class PlatformSeamsIntegrationTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $this->useSeamPluginsRoot();
        $this->writeSeamPlugin('seam-full', ['seam-results' => ['en' => 'seam-results-en']]);
        $this->writeSeamPlugin('seam-peer', ['seam-peer-key' => ['en' => 'seam-peer-en']]);
    }

    protected function tearDown(): void
    {
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    /**
     * Simulate the next request's boot in-process: fresh dictionary state, then
     * every active plugin's provider register() (Laravel registers a provider
     * class once per process, so it is re-run explicitly), then Core's boot.
     */
    private function freshBoot(): void
    {
        foreach ([RouteDictionarySourceRegistry::class, RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }
        $ext = app('cms.extension');
        $ext->flushRegistry();

        foreach ($ext->activePlugins() as $plugin) {
            $ext->ensurePluginAutoload($plugin);
            foreach ($plugin->providers as $class) {
                if (class_exists($class)) {
                    (new $class(app()))->register();
                }
            }
        }

        $ext->bootActivePlugins();
        app('cms.localized_routes')->finalize();
        \Tests\Support\ContentFixtures::signInOwnerActor(); // ownership changes need a signed-in actor
    }

    private function provider(string $slug): string
    {
        return 'Plugins\\'.Str::studly($slug).'\\'.Str::studly($slug).'ServiceProvider';
    }

    public function test_one_plugin_across_ownership_views_routes_and_lifecycle(): void
    {
        $this->makeSeamTheme('seam-int', ['plugins/seam-full/public/card.blade.php' => 'INT-THEME-CARD']);
        app('cms.extension')->activatePlugin('seam-full');
        $this->freshBoot();

        $post = $this->provider('seam-full')::own([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'Seam Owned', 'slug' => 'seam-owned', 'content' => '<p>owned</p>',
        ]);
        $path = parse_url(content_url($post, 'vi'), PHP_URL_PATH);

        // Active: everything resolves.
        $this->get($path)->assertOk();
        $this->assertStringContainsString('INT-THEME-CARD', view('seam-full::public.page')->render());
        $this->assertSame('seam-results-en', app('cms.localization.dictionary')->projectSegment(RouteKey::of('seam-results'), 'en'));
        $this->assertStringContainsString($path, implode(' ', array_column(app('cms.seo')->sitemap(), 'loc')));

        // Deactivate: owned content hidden (hide_404), namespace gone, no dictionary contribution next boot.
        app('cms.extension')->deactivatePlugin('seam-full');
        $this->freshBoot();

        $this->get($path)->assertNotFound();
        $this->assertFalse(View::exists('seam-full::public.page'));
        $this->assertNull(app('cms.localization.dictionary')->segmentFor(RouteKey::of('seam-results'), 'en'));
        $this->assertStringNotContainsString($path, implode(' ', array_column(app('cms.seo')->sitemap(), 'loc')));
        $this->assertSame('seam-full', app('cms.content_ownership')->ownershipOf($post->id)->owner, 'ownership survives');

        // Reactivate: all contracts resume with no data change.
        app('cms.extension')->activatePlugin('seam-full');
        $this->freshBoot();

        $this->get($path)->assertOk();
        $this->assertStringContainsString('INT-THEME-CARD', view('seam-full::public.page')->render());
        $this->assertSame('seam-results-en', app('cms.localization.dictionary')->projectSegment(RouteKey::of('seam-results'), 'en'));
    }

    public function test_owner_marked_unavailable_keeps_views_and_routes_but_answers_404(): void
    {
        $this->makeSeamTheme('seam-int2');
        $this->bootSeamPlugins('seam-full');
        $post = $this->provider('seam-full')::own([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi', 'title' => 'Seam Hidden', 'slug' => 'seam-hidden', 'content' => 'x',
        ]);

        // Availability is owner-only: Core/test code is refused, so flip it as the owner would.
        \Illuminate\Support\Facades\DB::table('cms_content_owner_policies')->where('content_id', $post->id)
            ->update(['availability' => ContentAvailability::Unavailable->value]);

        $this->get(parse_url(content_url($post, 'vi'), PHP_URL_PATH))->assertNotFound();
        $this->assertTrue(View::exists('seam-full::public.page'));
    }

    public function test_registration_order_does_not_change_the_result(): void
    {
        $this->makeSeamTheme('seam-order', [
            'plugins/seam-full/public/card.blade.php' => 'ORDER-FULL',
            'plugins/seam-peer/public/card.blade.php' => 'ORDER-PEER',
        ]);

        $results = [];

        foreach ([['seam-full', 'seam-peer'], ['seam-peer', 'seam-full']] as $order) {
            app('cms.settings')->set('extensions.active_plugins', $order);
            settings()->clearCache();
            $this->freshBoot();

            $dictionary = app('cms.localization.dictionary');
            $results[] = [
                $dictionary->projectSegment(RouteKey::of('seam-results'), 'en'),
                $dictionary->projectSegment(RouteKey::of('seam-peer-key'), 'en'),
                view('seam-full::public.card')->render(),
                view('seam-peer::public.card')->render(),
            ];
        }

        $this->assertSame($results[0], $results[1]);
        $this->assertSame(['seam-results-en', 'seam-peer-en', 'ORDER-FULL', 'ORDER-PEER'], $results[0]);
    }
}
