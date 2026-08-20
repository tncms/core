<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\Exceptions\LocalizedResourceResolverException;
use TheNguyen\CMS\Localization\LocaleSwitchTargetService;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\LocalizedResourceResolverRegistry;
use TheNguyen\CMS\Localization\Resolvers\HomeResolver;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Models\Content;
use Tests\TestCase;

/**
 * CORE-L10N.1B — P3 localized-resource platform.
 *
 * Proves Core resources resolve through the shared registry, that the switch-target service
 * produces correct per-locale targets (available + fallback), that resolver collisions fail
 * fast, and that the diagnostics command runs. Public switcher/hreflang byte-identity is pinned
 * separately by the existing LocalizedSlugTest (still green through this pipeline).
 */
final class LocalizedResourcePlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    private function service(): LocaleSwitchTargetService
    {
        return app(LocaleSwitchTargetService::class);
    }

    private function bilingualPage(): Content
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'trang-chu',
        ]);
    }

    /** @return array<string, \TheNguyen\CMS\Localization\SwitchTarget> */
    private function targetsByLocale(LocalizationContext $context): array
    {
        $out = [];
        foreach ($this->service()->targets($context) as $target) {
            $out[$target->locale] = $target;
        }

        return $out;
    }

    // ── built-in resolver selection ────────────────────────────────────────────────

    public function test_registry_holds_the_builtin_core_resolvers(): void
    {
        $keys = app(LocalizedResourceResolverRegistry::class)->keys();

        $this->assertEqualsCanonicalizing(['cms.home', 'cms.page', 'cms.post', 'cms.category', 'cms.tag'], $keys);
    }

    public function test_home_context_targets_every_locale_with_the_localized_home(): void
    {
        $context = new LocalizationContext('home');

        $this->assertSame('cms.home', $this->service()->selectedResolverKey($context));

        $targets = $this->targetsByLocale($context);
        $this->assertTrue($targets['en']->available);
        $this->assertTrue($targets['vi']->available);
        $this->assertSame('/', $targets['en']->url);      // default locale unprefixed home
        $this->assertSame('/vi', $targets['vi']->url);    // secondary locale prefixed home
    }

    public function test_page_context_resolves_translated_slugs_via_the_page_resolver(): void
    {
        $page = $this->bilingualPage();
        $context = new LocalizationContext('page', $page);

        $this->assertSame('cms.page', $this->service()->selectedResolverKey($context));

        $targets = $this->targetsByLocale($context);
        $this->assertTrue($targets['en']->active);            // current locale is en
        $this->assertSame('/home', $targets['en']->url);
        $this->assertSame('/vi/trang-chu', $targets['vi']->url);
        $this->assertSame('page', $targets['en']->canonicalType);
        $this->assertSame($page->id, $targets['en']->canonicalId);
    }

    public function test_missing_translation_falls_back_to_localized_home_and_is_unavailable(): void
    {
        // vi-only page: en has no slug.
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Chỉ tiếng Việt', 'slug' => 'chi-tieng-viet', 'content' => '<p>x</p>',
        ]);
        $context = new LocalizationContext('page', $page);

        $targets = $this->targetsByLocale($context);
        $this->assertFalse($targets['en']->available);
        $this->assertTrue($targets['en']->fallbackUsed);
        $this->assertSame('/', $targets['en']->url);          // localized home fallback (en default)
        $this->assertTrue($targets['vi']->available);
        $this->assertSame('/vi/chi-tieng-viet', $targets['vi']->url);
    }

    public function test_unsupported_context_uses_home_fallback_for_all_locales(): void
    {
        // A 'page' context with no content object (e.g. a forCustom() SEO page): no resolver
        // supports it, so every locale is an unavailable home fallback.
        $context = new LocalizationContext('page', null);

        $this->assertNull($this->service()->selectedResolverKey($context));

        foreach ($this->targetsByLocale($context) as $target) {
            $this->assertFalse($target->available);
            $this->assertTrue($target->fallbackUsed);
        }
    }

    // ── registry safety ────────────────────────────────────────────────────────────

    public function test_duplicate_resolver_key_fails_fast(): void
    {
        $registry = new LocalizedResourceResolverRegistry;
        $registry->register(new HomeResolver);

        $this->expectException(LocalizedResourceResolverException::class);
        $registry->register(new HomeResolver);
    }

    public function test_resolver_selection_is_deterministic_by_priority(): void
    {
        $registry = new LocalizedResourceResolverRegistry;
        $registry->register($this->fakeResolver('low', 10));
        $registry->register($this->fakeResolver('high', 90));

        $selected = $registry->select(new LocalizationContext('home'));
        $this->assertSame('high', $selected?->key());
    }

    private function fakeResolver(string $key, int $priority): LocalizedResourceResolverContract
    {
        return new class($key, $priority) implements LocalizedResourceResolverContract
        {
            public function __construct(private string $key, private int $priority) {}

            public function key(): string
            {
                return $this->key;
            }

            public function priority(): int
            {
                return $this->priority;
            }

            public function supports(LocalizationContext $context): bool
            {
                return true;
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                return null;
            }
        };
    }

    // ── diagnostics ────────────────────────────────────────────────────────────────

    public function test_diagnostics_command_runs(): void
    {
        $this->artisan('cms:localization:diagnose')
            ->assertExitCode(0);
    }
}
