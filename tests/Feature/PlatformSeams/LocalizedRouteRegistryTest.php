<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionarySourceRegistryException;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use TheNguyen\CMS\Localization\Routing\LocalizedRouteRegistrar;

/**
 * CORE-PLATFORM-SEAMS-R1 Phase 2 — plugin localized segments through the ONE
 * Core Route Dictionary: sources complete before finalization, identity-checked,
 * deterministic collisions (no last-writer-wins), consistent inbound matching
 * and URL generation, search routes deferred to the finalization point.
 */
final class LocalizedRouteRegistryTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    /** Lottery P05 frozen segment table (P05-PUBLIC-ROUTE-CONTRACT §1), vi default. */
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
        $this->freshDictionary();
    }

    protected function tearDown(): void
    {
        $this->tearDownSeamThemes();
        parent::tearDown();
    }

    /** A new boot's worth of dictionary state (the registry locks once per process boot). */
    private function freshDictionary(): void
    {
        foreach ([RouteDictionarySourceRegistry::class, \TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }
    }

    private function providerClass(string $slug): string
    {
        return 'Plugins\\'.\Illuminate\Support\Str::studly($slug).'\\'.\Illuminate\Support\Str::studly($slug).'ServiceProvider';
    }

    public function test_plugin_source_registers_in_provider_register_and_reaches_the_dictionary(): void
    {
        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->bootSeamPlugins('seam-lotto');

        $this->assertNull($this->providerClass('seam-lotto')::$registrationError);
        $dictionary = app('cms.localization.dictionary');

        foreach (self::LOTTERY_SHAPE as $key => $map) {
            $this->assertSame($map['en'], $dictionary->projectSegment(RouteKey::of($key), 'en'), $key);
            $this->assertSame($key, $dictionary->projectSegment(RouteKey::of($key), 'vi'), $key.' (default locale = route key)');
        }
    }

    public function test_inbound_matching_and_url_generation_agree_per_locale(): void
    {
        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->bootSeamPlugins('seam-lotto');
        app('router')->getRoutes()->refreshNameLookups();

        $vi = route('seam-lotto.region.vi', ['region' => 'mien-nam'], false);
        $en = route('seam-lotto.region.en', ['locale' => 'en', 'region' => 'mien-nam'], false);

        $this->assertSame('/xo-so-truc-tiep/mien-nam.html', $vi);
        $this->assertSame('/en/live-results/mien-nam.html', $en);

        $routes = app('router')->getRoutes();
        $this->assertTrue($routes->getByName('seam-lotto.region.vi')->matches(Request::create($vi)));
        $this->assertTrue($routes->getByName('seam-lotto.region.en')->matches(Request::create($en)));
        $this->assertFalse($routes->getByName('seam-lotto.region.en')->matches(Request::create('/en/xo-so-truc-tiep/mien-nam.html')));
        $this->assertFalse($routes->getByName('seam-lotto.region.vi')->matches(Request::create('/live-results/mien-nam.html')));
    }

    public function test_plugin_code_cannot_register_under_another_identity_or_priority(): void
    {
        $this->writeSeamPlugin('seam-spoof', ['spoof-key' => ['en' => 'spoof']], claimAs: 'someone-else');
        $this->bootSeamPlugins('seam-spoof');

        $this->assertStringContainsString('cannot be registered by plugin [seam-spoof]', (string) $this->providerClass('seam-spoof')::$registrationError);
        $this->assertNull(app('cms.localization.dictionary')->segmentFor(RouteKey::of('spoof-key'), 'en'));
    }

    public function test_duplicate_source_ids_are_refused(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        $registry->register(new PluginRouteDictionarySource('dup', ['dup-key' => ['en' => 'one']]));

        $this->expectException(RouteDictionarySourceRegistryException::class);
        $this->expectExceptionMessage('already registered');
        $registry->register(new PluginRouteDictionarySource('dup', ['dup-key' => ['en' => 'two']]));
    }

    public function test_conflicting_plugins_are_both_refused_regardless_of_order(): void
    {
        foreach ([['seam-a', 'seam-b'], ['seam-b', 'seam-a']] as $order) {
            $this->freshDictionary();
            $registry = app(RouteDictionarySourceRegistry::class);
            $registry->register(new PluginRouteDictionarySource('seam-c', ['bystander-key' => ['en' => 'bystander']]));

            foreach ($order as $slug) {
                $registry->register(new PluginRouteDictionarySource($slug, ['shared-key' => ['en' => 'from-'.$slug]]));
            }

            $dictionary = app('cms.localization.dictionary');

            $this->assertSame(['plugin:seam-a', 'plugin:seam-b'], array_keys($registry->refusals()), 'no last-writer-wins');
            $this->assertSame(['key_conflict:shared-key'], $registry->refusals()['plugin:seam-a']);
            $this->assertNull($dictionary->segmentFor(RouteKey::of('shared-key'), 'en'));
            $this->assertSame('bystander', $dictionary->projectSegment(RouteKey::of('bystander-key'), 'en'), 'uninvolved plugin unaffected');
            $this->assertSame('tim-kiem', $dictionary->projectSegment(RouteKey::of('search'), 'vi'), 'Core unaffected');
        }
    }

    public function test_cross_key_segment_collision_refuses_both_sources(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        $registry->register(new PluginRouteDictionarySource('seam-a', ['key-one' => ['en' => 'same-segment']]));
        $registry->register(new PluginRouteDictionarySource('seam-b', ['key-two' => ['en' => 'same-segment']]));

        app('cms.localization.dictionary');

        $this->assertSame(['plugin:seam-a', 'plugin:seam-b'], array_keys($registry->refusals()));
        $this->assertStringStartsWith('segment_collision:en:same-segment', $registry->refusals()['plugin:seam-b'][0]);
    }

    public function test_core_plugin_collision_refuses_the_plugin_only(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        // vi "san-pham" belongs to Core's "products"; "category" is a Core key.
        $registry->register(new PluginRouteDictionarySource('seam-a', ['my-shop' => ['vi' => 'san-pham', 'en' => 'my-shop']]));
        $registry->register(new PluginRouteDictionarySource('seam-b', ['category' => ['en' => 'kats']]));

        $dictionary = app('cms.localization.dictionary');

        $this->assertSame(['core_collision:my-shop:vi'], $registry->refusals()['plugin:seam-a']);
        $this->assertContains('core_key:category', $registry->refusals()['plugin:seam-b']);
        $this->assertSame('san-pham', $dictionary->projectSegment(RouteKey::of('products'), 'vi'));
    }

    /** @return array<string, array{mixed, string}> */
    public static function badSegments(): array
    {
        return [
            'empty' => ['', 'empty_segment'],
            'whitespace' => [' ket-qua', 'whitespace'],
            'slash' => ['a/b', 'separator'],
            'encoded slash' => ['a%2Fb', 'separator'],
            'dot segment' => ['..', 'separator'],
            'dotted' => ['a.b', 'separator'],
            'backslash' => ['a\\b', 'separator'],
            'null byte' => ["a\0b", 'control_character'],
            'zero width joiner' => ["a\u{200D}b", 'control_character'],
            'uppercase' => ['Ket-qua', 'uppercase'],
            'decomposed (NFD)' => ["ke\u{0302}\u{0301}t", 'unnormalized_unicode'],
            'fullwidth' => ["\u{FF4B}et", 'unnormalized_unicode'],
            'underscore' => ['ket_qua', 'invalid_segment'],
            'double hyphen' => ['ket--qua', 'invalid_segment'],
            'locale prefix' => ['en', 'reserved_segment'],
            'admin prefix' => ['admin', 'reserved_segment'],
            'core path' => ['account', 'reserved_segment'],
            'too long' => [str_repeat('a', 101), 'too_long'],
            'not a string' => [123, 'empty_segment'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSegments')]
    public function test_invalid_and_malicious_segments_are_refused(mixed $segment, string $reason): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        $registry->register(new PluginRouteDictionarySource('seam-bad', ['bad-key' => ['en' => $segment]]));

        app('cms.localization.dictionary');

        $this->assertSame(["{$reason}:bad-key:en"], $registry->refusals()['plugin:seam-bad'] ?? null);
    }

    public function test_invalid_and_reserved_route_keys_are_refused_at_construction(): void
    {
        foreach (['Bad_Key', '../x', 'search'] as $key) {
            try {
                new PluginRouteDictionarySource('seam-bad', [$key => ['en' => 'fine']]);
                $this->fail("key {$key} accepted");
            } catch (\TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_normalized_unicode_collision_is_detected(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        // Same visible word: NFC in two plugins, NFD in a third.
        $registry->register(new PluginRouteDictionarySource('seam-a', ['key-a' => ['en' => "k\u{1EBF}t"]]));
        $registry->register(new PluginRouteDictionarySource('seam-b', ['key-b' => ['en' => "ke\u{0302}\u{0301}t"]]));
        $registry->register(new PluginRouteDictionarySource('seam-c', ['key-c' => ['en' => "k\u{1EBF}t"]]));

        app('cms.localization.dictionary');

        $this->assertSame(['unnormalized_unicode:key-b:en'], $registry->refusals()['plugin:seam-b']);
        $this->assertStringStartsWith('segment_collision:en:', $registry->refusals()['plugin:seam-a'][0]);
        $this->assertStringStartsWith('segment_collision:en:', $registry->refusals()['plugin:seam-c'][0]);
    }

    /**
     * CORE-PLATFORM-SEAMS-R2 replaced the R1 "missing locale → route key" fallback
     * (owner decision): a missing secondary locale is refused unless the source
     * explicitly opts in; see LocalizedRouteFallbackPolicyTest for the full matrix.
     */
    public function test_missing_secondary_locale_is_refused_unless_the_source_opts_in(): void
    {
        app('cms.language')->create(['code' => 'de', 'name' => 'Deutsch', 'is_default' => false, 'is_active' => true, 'sort_order' => 3]);
        $this->freshDictionary();
        $registry = app(RouteDictionarySourceRegistry::class);
        $registry->register(new PluginRouteDictionarySource('seam-lotto', self::LOTTERY_SHAPE));

        $dictionary = app('cms.localization.dictionary');

        $this->assertContains('missing_locale:xo-so-truc-tiep:de', $registry->refusals()['plugin:seam-lotto']);
        $this->assertNull($dictionary->segmentFor(RouteKey::of('xo-so-truc-tiep'), 'de'));
        $this->assertSame([], $registry->fallbacks());
    }

    public function test_refused_plugin_serves_no_routes_while_others_do(): void
    {
        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->writeSeamPlugin('seam-clash', ['clash-key' => ['vi' => 'san-pham']]);
        $this->bootSeamPlugins('seam-lotto', 'seam-clash');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertNotNull(Route::getRoutes()->getByName('seam-lotto.region.en'));
        $this->assertNull(Route::getRoutes()->getByName('seam-clash.region.vi'), 'refused plugin fails closed alone');
        $this->assertTrue(app(RouteDictionarySourceRegistry::class)->isRefused('plugin:seam-clash'));
    }

    public function test_route_cache_is_invalidated_on_activation_and_deactivation(): void
    {
        $cache = app()->getCachedRoutesPath();
        $this->assertFileDoesNotExist($cache, 'precondition: no real route cache in this checkout');

        try {
            $this->writeSeamPlugin('seam-cache', ['cache-key' => ['en' => 'cache-en']]);
            file_put_contents($cache, '<?php return [];');
            app()->instance('routes.cached', true); // the harness pins it false; emulate `route:cache`
            $this->assertTrue(app()->routesAreCached());

            $this->assertTrue(app('cms.extension')->activatePlugin('seam-cache'));
            $this->assertFileDoesNotExist($cache);

            file_put_contents($cache, '<?php return [];');
            $this->assertTrue(app('cms.extension')->deactivatePlugin('seam-cache'));
            $this->assertFileDoesNotExist($cache);
        } finally {
            @unlink($cache);
            app()->forgetInstance('routes.cached');
        }
    }

    public function test_search_consumes_contributed_keys(): void
    {
        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->bootSeamPlugins('seam-lotto');
        app('router')->getRoutes()->refreshNameLookups();

        app('cms.search')->register(new class implements \App\Search\Contracts\SearchProvider
        {
            public function key(): string
            {
                return 'seam-lotto';
            }

            public function definitions(): array
            {
                return [new class implements \App\Search\Contracts\SearchableEntityDefinition
                {
                    public function type(): string
                    {
                        return 'seam-lotto';
                    }

                    public function label(): string
                    {
                        return 'Seam Lotto';
                    }

                    public function modelClass(): string
                    {
                        return \stdClass::class;
                    }

                    public function searchableFields(): array
                    {
                        return ['title'];
                    }

                    public function supportsLocales(): bool
                    {
                        return true;
                    }

                    public function visibilityRules(): array
                    {
                        return ['public'];
                    }

                    public function resolveTitle(object $entity, string $locale): string
                    {
                        return '';
                    }

                    public function resolveUrl(object $entity, string $locale): ?string
                    {
                        return null;
                    }
                }];
            }

            public function search(\App\Search\SearchQuery $query): array
            {
                $locale = $query->locale;
                $params = $locale === 'vi' ? ['region' => 'mien-nam'] : ['locale' => $locale, 'region' => 'mien-nam'];

                return [new \App\Search\SearchResult('seam-lotto', 1, 'Mien Nam', '', route('seam-lotto.region.'.$locale, $params, false), 1.0, $locale)];
            }
        });

        $manager = app(\App\Search\Contracts\SearchManagerInterface::class);
        $vi = $manager->search(\App\Search\SearchQuery::create('mien', 'vi', []))->results;
        $en = $manager->search(\App\Search\SearchQuery::create('mien', 'en', []))->results;

        $this->assertSame('/xo-so-truc-tiep/mien-nam.html', collect($vi)->firstWhere('type', 'seam-lotto')->url);
        $this->assertSame('/en/live-results/mien-nam.html', collect($en)->firstWhere('type', 'seam-lotto')->url);
    }

    public function test_registration_after_finalization_fails_closed(): void
    {
        app('cms.localized_routes')->finalize();

        $this->expectException(RouteDictionarySourceRegistryException::class);
        app(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource('late', ['late-key' => ['en' => 'late']]));
    }

    public function test_deferred_registrars_run_once_after_sources_and_in_order(): void
    {
        $registrar = app('cms.localized_routes');
        $calls = [];
        $registrar->defer(function () use (&$calls) {
            $calls[] = ['first', app(RouteDictionarySourceRegistry::class)->isLocked()];
        });
        $registrar->defer(function () use (&$calls) {
            $calls[] = ['second', true];
        });

        // A plugin source registered AFTER the deferral but before finalization still lands.
        app(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource('seam-late', ['late-key' => ['en' => 'late-ok']]));

        $this->assertSame([], $calls);
        $registrar->finalize();
        $registrar->finalize();

        $this->assertSame([['first', true], ['second', true]], $calls);
        $this->assertTrue($registrar->isFinalized());
        $this->assertSame('late-ok', app('cms.localization.dictionary')->projectSegment(RouteKey::of('late-key'), 'en'));
    }

    public function test_host_search_no_longer_composes_the_dictionary_before_plugins(): void
    {
        // Simulate the real provider order: the host SearchServiceProvider boots first.
        $this->freshDictionary();
        (new \App\Providers\SearchServiceProvider(app()))->boot();

        $this->assertFalse(app(RouteDictionarySourceRegistry::class)->isLocked(), 'search must defer, not compose');

        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $this->bootSeamPlugins('seam-lotto');
        $this->assertNull($this->providerClass('seam-lotto')::$registrationError, 'the P05 lock gap is closed');

        app('cms.localized_routes')->finalize();
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertSame('live-results', app('cms.localization.dictionary')->projectSegment(RouteKey::of('xo-so-truc-tiep'), 'en'));
        $this->assertNotNull(Route::getRoutes()->getByName('cms.search.localized.vi'), 'search localized route registered at finalization');
    }

    public function test_search_localized_route_is_ordered_before_the_catch_all_on_a_normal_boot(): void
    {
        $uris = array_map(fn ($r) => $r->getName(), app('router')->getRoutes()->getRoutes());
        $search = array_search('cms.search.localized.vi', $uris, true);
        $catchAll = array_search('cms.resolve.localized', $uris, true);

        $this->assertIsInt($search);
        if ($catchAll !== false) {
            $this->assertLessThan($catchAll, $search);
        }
        $this->assertSame(LocalizedRouteRegistrar::class, get_class(app('cms.localized_routes')));
    }

    public function test_inactive_plugin_contributes_nothing(): void
    {
        $this->writeSeamPlugin('seam-off', ['off-key' => ['en' => 'off-segment']]);
        app('cms.extension')->flushRegistry();
        app('cms.extension')->bootActivePlugins();

        $this->assertNull(app('cms.localization.dictionary')->segmentFor(RouteKey::of('off-key'), 'en'));
        $this->assertNull(Route::getRoutes()->getByName('seam-off.region.en'));
    }

    public function test_deferred_routes_register_between_plugin_passes_ahead_of_plugin_routes(): void
    {
        $this->freshDictionary();
        $order = [];
        app('cms.localized_routes')->defer(function () use (&$order) {
            $order[] = 'deferred:'.count(app('router')->getRoutes()->getRoutes());
        });

        $this->writeSeamPlugin('seam-lotto', self::LOTTERY_SHAPE);
        $before = count(app('router')->getRoutes()->getRoutes());
        $this->bootSeamPlugins('seam-lotto');

        $this->assertSame(['deferred:'.$before], $order, 'ran after providers, before plugin routes');
        $this->assertNull($this->providerClass('seam-lotto')::$registrationError);
        $this->assertGreaterThan($before, count(app('router')->getRoutes()->getRoutes()));
    }

    public function test_a_failing_composition_still_runs_deferred_registrars(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);
        $registry->register(new PluginRouteDictionarySource('seam-a', ['k' => ['en' => 'x']]));
        $registry->register(new PluginRouteDictionarySource('seam-b', ['k' => ['en' => 'y']]));
        $ran = false;
        app('cms.localized_routes')->defer(function () use (&$ran) {
            $ran = true;
        });

        app('cms.localized_routes')->finalize();
        $this->assertTrue($ran);
    }
}
