<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;

/**
 * CORE-PLATFORM-SEAMS-R2 Gate B — missing-locale policy for plugin route
 * segments: explicit segment → default locale uses the route key (existing
 * Core contract, validated) → opted-in fallback to the default-locale segment
 * (materialized, validated, collision-checked) → otherwise refused.
 */
final class LocalizedRouteFallbackPolicyTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    /** Lottery P05 frozen table — must stay accepted on a vi+en site. */
    private const LOTTERY_SHAPE = [
        'xo-so-truc-tiep' => ['en' => 'live-results'],
        'ket-qua-xo-so' => ['en' => 'lottery-results'],
        'so-dau-duoi' => ['en' => 'head-tail'],
        'thong-ke-xo-so' => ['en' => 'lottery-statistics'],
        'lo-to-tinh' => ['en' => 'province-loto'],
        'in-ve-do' => ['en' => 'print-slips'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useSeamPluginsRoot();
        $this->fresh();
    }

    protected function tearDown(): void
    {
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    private function fresh(): void
    {
        foreach ([RouteDictionarySourceRegistry::class, RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }
    }

    private function addGerman(): void
    {
        app('cms.language')->create(['code' => 'de', 'name' => 'Deutsch', 'is_default' => false, 'is_active' => true, 'sort_order' => 3]);
        $this->fresh();
    }

    private function compose(PluginRouteDictionarySource ...$sources): RouteDictionarySourceRegistry
    {
        $registry = app(RouteDictionarySourceRegistry::class);

        foreach ($sources as $source) {
            $registry->register($source);
        }

        app('cms.localization.dictionary');

        return $registry;
    }

    private function segment(string $key, string $locale): ?string
    {
        return app('cms.localization.dictionary')->segmentFor(RouteKey::of($key), $locale)?->value;
    }

    public function test_complete_locale_map_is_accepted_without_fallbacks(): void
    {
        $this->addGerman();
        $registry = $this->compose(new PluginRouteDictionarySource('full', ['ve-so' => ['vi' => 've-so', 'en' => 'tickets', 'de' => 'lose']]));

        $this->assertSame([], $registry->refusals());
        $this->assertSame([], $registry->fallbacks());
        $this->assertSame('lose', $this->segment('ve-so', 'de'));
    }

    public function test_missing_default_locale_uses_the_validated_route_key(): void
    {
        $registry = $this->compose(new PluginRouteDictionarySource('nodefault', ['ve-so' => ['en' => 'tickets']]));

        $this->assertSame([], $registry->refusals());
        $this->assertSame('ve-so', app('cms.localization.dictionary')->projectSegment(RouteKey::of('ve-so'), 'vi'));
        $this->assertSame('tickets', $this->segment('ve-so', 'en'));
    }

    public function test_route_key_that_is_not_a_valid_public_segment_for_the_default_locale_is_refused(): void
    {
        // "en" is a valid RouteKey but a locale prefix — not a publishable default segment.
        $registry = $this->compose(new PluginRouteDictionarySource('badkey', ['en' => ['en' => 'english']]));

        $this->assertContains('reserved_segment:en:vi', $registry->refusals()['plugin:badkey']);
    }

    public function test_missing_secondary_locale_is_refused_with_a_deterministic_diagnostic(): void
    {
        $registry = $this->compose(new PluginRouteDictionarySource('partial', ['ve-so' => ['vi' => 've-so']]));

        $this->assertSame(['missing_locale:ve-so:en'], $registry->refusals()['plugin:partial']);
        $this->assertNull($this->segment('ve-so', 'en'));
        $this->assertSame('ve-so', app('cms.localization.dictionary')->projectSegment(RouteKey::of('ve-so'), 'en'), 'Core projection of an unknown key is unchanged');
    }

    public function test_explicit_fallback_materializes_the_default_segment(): void
    {
        $this->addGerman();
        $registry = $this->compose(new PluginRouteDictionarySource('optin', ['ve-so' => ['vi' => 'xo-so-ve', 'en' => 'tickets']], fallbackToDefaultSegment: true));

        $this->assertSame([], $registry->refusals());
        $this->assertSame('xo-so-ve', $this->segment('ve-so', 'de'), 'the DEFAULT-LOCALE segment, not the route key');
        $this->assertSame([['source' => 'plugin:optin', 'key' => 've-so', 'locale' => 'de']], $registry->fallbacks());
        $this->assertSame('ve-so', app('cms.localization.dictionary')->keyFor('xo-so-ve', 'de')?->value, 'inbound matches outbound');
    }

    public function test_explicit_fallback_disabled_by_default(): void
    {
        $this->addGerman();
        $registry = $this->compose(new PluginRouteDictionarySource('optout', ['ve-so' => ['vi' => 've-so', 'en' => 'tickets']]));

        $this->assertSame(['missing_locale:ve-so:de'], $registry->refusals()['plugin:optout']);
    }

    public function test_invalid_semantic_keys_never_become_urls(): void
    {
        foreach (['lottery_results', 'Lottery-Results', '../x', ''] as $key) {
            try {
                new PluginRouteDictionarySource('bad', [$key => ['en' => 'fine']]);
                $this->fail("key [{$key}] accepted");
            } catch (RouteKeyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_fallback_segment_is_unicode_normalized_and_validated(): void
    {
        $this->addGerman();
        // NFD default segment + fallback: refused for vi AND (materialized) de.
        $registry = $this->compose(new PluginRouteDictionarySource('nfd', ['ve-so' => ['vi' => "ke\u{0302}\u{0301}t", 'en' => 'tickets']], fallbackToDefaultSegment: true));

        $reasons = $registry->refusals()['plugin:nfd'];
        $this->assertContains('unnormalized_unicode:ve-so:vi', $reasons);
        $this->assertContains('unnormalized_unicode:ve-so:de', $reasons);
        $this->assertSame([], $registry->fallbacks(), 'no fallback reported for a refused source');
    }

    public function test_fallback_cannot_bypass_collision_checks(): void
    {
        $this->addGerman();
        // Plugin A falls back to "ket-qua" for de; plugin B explicitly claims de "ket-qua".
        $registry = $this->compose(
            new PluginRouteDictionarySource('fall-a', ['key-a' => ['vi' => 'ket-qua', 'en' => 'results']], fallbackToDefaultSegment: true),
            new PluginRouteDictionarySource('claim-b', ['key-b' => ['vi' => 'khac', 'en' => 'other', 'de' => 'ket-qua']]),
        );

        $this->assertStringStartsWith('segment_collision:de:ket-qua', $registry->refusals()['plugin:fall-a'][0]);
        $this->assertArrayHasKey('plugin:claim-b', $registry->refusals());

        // ...and a fallback onto a Core segment is a Core collision.
        $this->fresh();
        $registry = $this->compose(new PluginRouteDictionarySource('core-fall', ['key-c' => ['vi' => 'thuong-hieu', 'en' => 'shop']], fallbackToDefaultSegment: true));
        $this->assertContains('core_collision:key-c:vi', $registry->refusals()['plugin:core-fall']);
    }

    public function test_locale_configuration_change_refuses_then_accepts_deterministically(): void
    {
        $this->assertSame([], $this->compose(new PluginRouteDictionarySource('lotto', self::LOTTERY_SHAPE))->refusals(), 'Lottery table unchanged on vi+en');

        $this->addGerman();
        $this->assertCount(6, $this->compose(new PluginRouteDictionarySource('lotto', self::LOTTERY_SHAPE))->refusals()['plugin:lotto']);

        $this->fresh();
        $registry = $this->compose(new PluginRouteDictionarySource('lotto', self::LOTTERY_SHAPE, fallbackToDefaultSegment: true));
        $this->assertSame([], $registry->refusals());
        $this->assertSame('xo-so-truc-tiep', $this->segment('xo-so-truc-tiep', 'de'));
    }

    public function test_refused_source_registers_no_routes_at_all(): void
    {
        $this->addGerman();
        $this->writeSeamPlugin('seam-partial', ['ve-so' => ['en' => 'tickets']]);
        $this->bootSeamPlugins('seam-partial');
        app('router')->getRoutes()->refreshNameLookups();

        foreach (['vi', 'en', 'de'] as $locale) {
            $this->assertNull(Route::getRoutes()->getByName('seam-partial.region.'.$locale), "no partial route for {$locale}");
        }
    }

    public function test_activation_deactivation_and_route_cache_follow_the_policy(): void
    {
        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->bootSeamPlugins('seam-lotto');
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertSame('/en/live-results/mien-nam.html', route('seam-lotto.region.en', ['locale' => 'en', 'region' => 'mien-nam'], false));

        $cache = app()->getCachedRoutesPath();
        file_put_contents($cache, '<?php return [];');
        app()->instance('routes.cached', true);

        try {
            app('cms.extension')->deactivatePlugin('seam-lotto');
            $this->assertFileDoesNotExist($cache);
        } finally {
            @unlink($cache);
            app()->forgetInstance('routes.cached');
        }

        $this->fresh();
        app('cms.extension')->flushRegistry();
        app('cms.extension')->bootActivePlugins();
        $this->assertNull(app('cms.localization.dictionary')->segmentFor(RouteKey::of('xo-so-truc-tiep'), 'en'));
    }
}
