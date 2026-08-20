<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use Tests\TestCase;

/**
 * P6.3A — the REAL plugin lifecycle contributing a Route Dictionary source, driven through the
 * ExtensionManager two-pass boot with the dedicated test fixture at
 * tests/Fixtures/Plugins/dictionary-provider-plugin. This is the integration counterpart to the
 * isolated source/registry unit tests.
 *
 *   ExtensionManager (discover → activate → two-pass boot) → provider register()
 *     → RouteDictionarySourceRegistry → Composer → Loader → immutable RouteSegmentDictionary
 *
 * The fixture is discoverable ONLY because these tests point cms.paths.plugins at the fixtures dir;
 * it is never seen during production boot.
 */
final class PluginDictionaryProviderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_SLUG = 'dictionary-provider-plugin';

    protected function setUp(): void
    {
        parent::setUp();

        // Point plugin discovery at the fixtures directory and give the boot a fresh, UNLOCKED
        // registry (the app's was locked when the Dictionary resolved during the harness boot).
        config(['cms.paths.plugins' => base_path('tests/Fixtures/Plugins')]);
        $this->freshRegistry();
        app('cms.extension')->flushRegistry();
    }

    private function freshRegistry(): void
    {
        $this->app->instance(RouteDictionarySourceRegistry::class, new RouteDictionarySourceRegistry);
        $this->app->forgetInstance('cms.localization.dictionary');
        $this->app->forgetInstance(RouteDictionaryComposer::class);
    }

    public function test_fixture_is_discoverable_when_the_plugins_root_points_at_it(): void
    {
        $this->assertNotNull(app('cms.extension')->findPlugin(self::FIXTURE_SLUG));
    }

    public function test_activated_fixture_contributes_its_source_through_the_real_lifecycle(): void
    {
        $ext = app('cms.extension');
        $ext->activatePlugin(self::FIXTURE_SLUG);
        $ext->flushRegistry();
        $ext->bootActivePlugins();

        $dict = app('cms.localization.dictionary');

        // The fixture's plugin-owned key is now projectable through the immutable Runtime Dictionary.
        $this->assertSame('cau-hoi-thuong-gap', $dict->projectSegment(RouteKey::of('faq'), 'vi'));
        $this->assertSame('faq', $dict->projectSegment(RouteKey::of('faq'), 'de'));

        // Core segments are untouched by the plugin source.
        $frozen = PlatformRouteDictionary::make();
        $this->assertSame(
            $frozen->projectSegment(RouteKey::of('products'), 'vi'),
            $dict->projectSegment(RouteKey::of('products'), 'vi'),
        );
    }

    public function test_without_activation_the_dictionary_is_byte_identical_to_core(): void
    {
        $dict = app('cms.localization.dictionary');

        $this->assertNull($dict->segmentFor(RouteKey::of('faq'), 'vi'));

        $frozen = PlatformRouteDictionary::make();
        foreach (PlatformRouteDictionary::data() as $key => $localeMap) {
            $routeKey = RouteKey::of($key);
            foreach (array_keys($localeMap) as $locale) {
                $this->assertSame(
                    $frozen->projectSegment($routeKey, $locale),
                    $dict->projectSegment($routeKey, $locale),
                );
            }
        }
    }

    public function test_deactivation_removes_the_contribution_on_rebuild(): void
    {
        $ext = app('cms.extension');

        $ext->activatePlugin(self::FIXTURE_SLUG);
        $ext->flushRegistry();
        $ext->bootActivePlugins();
        $this->assertSame('cau-hoi-thuong-gap', app('cms.localization.dictionary')->projectSegment(RouteKey::of('faq'), 'vi'));

        // Deactivate, then rebuild from a fresh registry: the source is no longer contributed.
        $ext->deactivatePlugin(self::FIXTURE_SLUG);
        $this->freshRegistry();
        $ext->flushRegistry();
        $ext->bootActivePlugins();

        $this->assertNull(app('cms.localization.dictionary')->segmentFor(RouteKey::of('faq'), 'vi'));
    }
}
