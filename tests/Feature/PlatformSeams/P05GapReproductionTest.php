<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

/**
 * CORE-LOTTERY-P05-SEAMS-1 Gate A — the two P05 gaps, reproduced with neutral
 * fixtures and ONLY APIs that exist before and after the phase, so the same
 * file is red on the pre-phase Core (07469e98) and green afterwards.
 */
final class P05GapReproductionTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useSeamPluginsRoot();
    }

    protected function tearDown(): void
    {
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    /** Gap 2: the host search provider boots first and must not freeze the dictionary. */
    public function test_gap_route_dictionary_accepts_a_plugin_source_after_host_search_boot(): void
    {
        foreach ([RouteDictionarySourceRegistry::class, RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }

        // Real provider order: App\Providers\SearchServiceProvider boots before CmsServiceProvider.
        (new \App\Providers\SearchServiceProvider(app()))->boot();

        // A neutral plugin's documented registration (pre-phase: throws "registered after the Dictionary was composed").
        app(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource('neutral-plugin', ['neutral-key' => ['en' => 'neutral-en']]));

        if (app()->bound('cms.localized_routes')) {
            app('cms.localized_routes')->finalize();
        }

        $this->assertSame('neutral-en', app('cms.localization.dictionary')->projectSegment(RouteKey::of('neutral-key'), 'en'));
    }

    /** Gap 1: an active theme cannot override a plugin view. */
    public function test_gap_active_theme_overrides_a_plugin_view(): void
    {
        $this->writeSeamPlugin('neutral-views');
        $this->makeSeamTheme('neutral-theme', ['plugins/neutral-views/public/card.blade.php' => 'THEME-WINS']);
        $this->bootSeamPlugins('neutral-views');

        $this->assertSame('THEME-WINS', view('neutral-views::public.card')->render());
    }
}
