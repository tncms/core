<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\PublicLocaleContextContract;
use TheNguyen\CMS\Localization\LanguageConfiguration;
use TheNguyen\CMS\Localization\PublicLocaleContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use Tests\TestCase;

/**
 * CORE-L10N.1B — P1 Localization Platform foundation.
 *
 * Proves the read-only facades expose exactly what the existing Core language authority holds
 * (single source of truth, no drift) and that RouteDescriptor is an immutable, URL-free facts
 * object. Zero behaviour change: these contracts only read from LanguageManager +
 * LocalePreferenceManager + the language.* settings.
 */
final class LanguagePlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app()->setLocale('en');
    }

    private function config(): LanguageConfigurationContract
    {
        return app(LanguageConfigurationContract::class);
    }

    private function publicLocale(): PublicLocaleContextContract
    {
        return app(PublicLocaleContextContract::class);
    }

    // ── single-authority: config reads live from LanguageManager ───────────────────

    public function test_enabled_locales_come_from_the_language_authority(): void
    {
        $this->assertSame(['en', 'vi'], $this->config()->enabledLocales());

        // Add an inactive language: it must NOT appear as enabled.
        app('cms.language')->create(['code' => 'fr', 'name' => 'French', 'is_active' => false, 'sort_order' => 3]);
        app('cms.language')->active(); // (memo already warm; a fresh manager would re-read)

        $this->assertNotContains('fr', app(LanguageConfiguration::class)->enabledLocales());
    }

    public function test_default_and_fallback_come_from_core(): void
    {
        $this->assertSame('en', $this->config()->defaultLocale());
        $this->assertSame('en', $this->config()->fallbackLocale());
    }

    public function test_multilingual_enabled_reflects_the_active_locale_count(): void
    {
        $this->assertTrue($this->config()->multilingualEnabled());

        $vi = app('cms.language')->find('vi');
        app('cms.language')->update($vi, ['is_active' => false]);

        $this->assertFalse(app(LanguageConfiguration::class)->multilingualEnabled());
    }

    public function test_prefix_policy_matches_the_language_authority(): void
    {
        // Default policy: default locale unprefixed, secondary prefixed.
        $this->assertSame('', $this->config()->prefixFor('en'));
        $this->assertSame('/vi', $this->config()->prefixFor('vi'));
        $this->assertFalse($this->config()->prefixesDefaultLocale());

        // Flip prefix_default: the default locale becomes prefixed too.
        app('cms.settings')->set('language.prefix_default', true, 'boolean');

        $this->assertTrue($this->config()->prefixesDefaultLocale());
        $this->assertSame('/en', $this->config()->prefixFor('en'));
    }

    public function test_routing_strategy_defaults_to_prefix_and_reads_the_setting(): void
    {
        $this->assertSame('prefix', $this->config()->routingStrategy());

        app('cms.settings')->set('language.routing_strategy', 'session', 'string');

        $this->assertSame('session', $this->config()->routingStrategy());
    }

    public function test_metadata_is_sourced_from_the_language_record(): void
    {
        $vi = $this->config()->metadata('vi');

        $this->assertNotNull($vi);
        $this->assertSame('vi', $vi->code);
        $this->assertSame('Tiếng Việt', $vi->nativeName);
        $this->assertSame('ltr', $vi->direction);
        $this->assertFalse($vi->isDefault);
        $this->assertNull($this->config()->metadata('zz')); // unknown code
    }

    // ── public locale context ──────────────────────────────────────────────────────

    public function test_public_locale_context_reflects_the_current_core_locale(): void
    {
        $this->assertSame('en', $this->publicLocale()->current());
        $this->assertSame('en', $this->publicLocale()->default());

        app('cms.language')->setCurrent('vi');
        $this->assertSame('vi', $this->publicLocale()->current());
        $this->assertTrue($this->publicLocale()->isPublic('vi'));
        $this->assertFalse($this->publicLocale()->isPublic('zz'));
    }

    public function test_admin_and_editing_locales_are_independent_and_read_only(): void
    {
        // No setter exists on the contract — plugins cannot mutate locale.
        $this->assertFalse(method_exists($this->publicLocale(), 'setCurrent'));

        // Defaults with no signal.
        $this->assertSame('en', $this->publicLocale()->adminLocale());
        $this->assertSame('en', $this->publicLocale()->editingLocale());
    }

    // ── RouteDescriptor: immutable, URL-free facts ─────────────────────────────────

    public function test_route_descriptor_is_immutable_and_carries_no_url(): void
    {
        $descriptor = new RouteDescriptor(
            resolverKey: 'cms.post',
            canonicalType: 'post',
            canonicalId: 42,
            routeName: 'cms.post',
            routeParameters: ['slug' => 'bai-viet'],
            metadata: ['base' => 'blog'],
        );

        $this->assertSame('post', $descriptor->canonicalType);
        $this->assertSame(42, $descriptor->canonicalId);
        $this->assertSame(['slug' => 'bai-viet'], $descriptor->routeParameters);

        $array = $descriptor->toArray();
        $this->assertArrayNotHasKey('url', $array);
        $this->assertArrayNotHasKey('prefix', $array);
        $this->assertArrayNotHasKey('href', $array);

        // Readonly promoted properties reject mutation.
        $reflection = new \ReflectionProperty(RouteDescriptor::class, 'routeParameters');
        $this->assertTrue($reflection->isReadOnly());
    }

    // ── container wiring ───────────────────────────────────────────────────────────

    public function test_contracts_resolve_to_the_platform_implementations(): void
    {
        $this->assertInstanceOf(LanguageConfiguration::class, app(LanguageConfigurationContract::class));
        $this->assertInstanceOf(PublicLocaleContext::class, app(PublicLocaleContextContract::class));
        $this->assertSame(app('cms.localization.config'), app(LanguageConfigurationContract::class));
    }
}
