<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\Composition\StoreRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryLoader;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use Tests\TestCase;

/**
 * P6.3 — plugins contribute build-time Route Dictionary sources; the Runtime is unaware.
 *
 * The registry is bound at boot. With no active plugin the composed Dictionary is byte-identical
 * to core (compatibility). A registered plugin source flows through composer → loader into the
 * immutable Dictionary. (The REAL plugin lifecycle is covered by
 * {@see PluginDictionaryProviderLifecycleTest} against the test fixture; here the source is an
 * in-test fake for isolated behavior.)
 */
final class PluginDictionaryProviderBootTest extends TestCase
{
    public function test_source_registry_is_bound_as_a_singleton(): void
    {
        $registry = app(RouteDictionarySourceRegistry::class);

        $this->assertInstanceOf(RouteDictionarySourceRegistry::class, $registry);
        $this->assertSame($registry, app(RouteDictionarySourceRegistry::class));
    }

    public function test_dictionary_is_byte_identical_when_no_plugin_registers_a_source(): void
    {
        $dict = app('cms.localization.dictionary');
        $frozen = PlatformRouteDictionary::make();

        // No plugin is active in the test suite → the composed Dictionary equals the frozen seed.
        foreach (PlatformRouteDictionary::data() as $key => $localeMap) {
            $routeKey = RouteKey::of($key);

            foreach (array_keys($localeMap) as $locale) {
                $this->assertSame(
                    $frozen->projectSegment($routeKey, $locale),
                    $dict->projectSegment($routeKey, $locale),
                );
            }
        }

        // A plugin-only key ("faq") is absent — no provider contributed it.
        $this->assertNull($dict->segmentFor(RouteKey::of('faq'), 'vi'));
    }

    public function test_a_registered_plugin_source_flows_into_a_composed_dictionary(): void
    {
        // Compose exactly as the CmsServiceProvider does: persistence base first, then the
        // plugin source — but on a FRESH registry (the app's is already locked at boot).
        $registry = new RouteDictionarySourceRegistry;
        $registry->register(new PluginRouteDictionarySource('example-plugin', [
            'faq' => ['vi' => 'cau-hoi-thuong-gap', 'de' => 'faq'],
        ]));

        $composer = new RouteDictionaryComposer([
            new StoreRouteDictionarySource(app(RouteDictionaryStoreInterface::class)),
            ...$registry->all(),
        ]);
        $dict = (new RouteDictionaryLoader($composer))->load();

        // Plugin-contributed localized segments are now projectable.
        $this->assertSame('cau-hoi-thuong-gap', $dict->projectSegment(RouteKey::of('faq'), 'vi'));
        $this->assertSame('faq', $dict->projectSegment(RouteKey::of('faq'), 'de'));

        // Core segments are untouched by the plugin source.
        $frozen = PlatformRouteDictionary::make();
        $products = RouteKey::of('products');
        $this->assertSame($frozen->projectSegment($products, 'vi'), $dict->projectSegment($products, 'vi'));
    }
}
