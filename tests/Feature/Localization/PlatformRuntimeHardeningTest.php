<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\CurrentResourceContext;
use TheNguyen\CMS\Localization\CurrentResourceReference;
use TheNguyen\CMS\Localization\LocaleSwitchTargetService;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Localization\SwitchTarget;
use TheNguyen\CMS\Services\LocalizedContentUrlService;
use Tests\TestCase;

/**
 * P5F — Platform Runtime Invariants & Hardening.
 *
 * The Platform Resource Runtime has already been PROVEN (P5A–P5E). These guards
 * prove it cannot accidentally REGRESS. They are additive over the existing
 * freeze/authority guards ({@see CoreLocalizationFreezeTest},
 * {@see LocalizationAuthorityGuardTest}, {@see \Tests\Feature\Ecommerce\PlatformResourceAcceptanceTest})
 * and cover the four invariant classes those did not yet assert directly:
 *
 *   1. Determinism — identical inputs always produce identical URLs, switch
 *      targets, and resource identity (no hidden mutation between calls).
 *   2. Fail-closed — every missing/unknown/unsupported input degrades to a safe
 *      value (null / unavailable / rejected), never an exception or a leak.
 *   3. Resolver purity — Core resolvers resolve FACTS only; they never write the
 *      current-resource authority, own SEO, generate URLs, or render.
 *   4. Reference identity-only — the current-resource reference exposes WHAT the
 *      resource is and nothing else (no URL, locale, or SEO surface).
 *
 * Hardening only: no runtime is modified, no behavior is changed.
 */
final class PlatformRuntimeHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        // A configured-but-inactive locale to prove the disabled-locale fail-closed path.
        $language->create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => false, 'sort_order' => 3]);
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    private function service(): LocalizedContentUrlService
    {
        return app('cms.localization.content_url');
    }

    private function switchTargets(): LocaleSwitchTargetService
    {
        return app('cms.localization.switch_targets');
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_localized_url_is_byte_identical_across_repeated_calls(): void
    {
        app('cms.localization.resolvers')->register($this->widgetResolver());
        $context = new LocalizationContext('acme.widget');

        $en = array_map(fn (): ?string => $this->service()->forResource(new \stdClass, 'en', $context), range(1, 3));
        $vi = array_map(fn (): ?string => $this->service()->forResource(new \stdClass, 'vi', $context), range(1, 3));

        $this->assertSame(['/acme/gadget', '/acme/gadget', '/acme/gadget'], $en);
        $this->assertSame(['/vi/acme/gadget', '/vi/acme/gadget', '/vi/acme/gadget'], $vi);
    }

    public function test_switch_targets_are_deterministic_across_repeated_calls(): void
    {
        app('cms.localization.resolvers')->register($this->widgetResolver());
        $context = new LocalizationContext('acme.widget');

        $this->assertSame($this->projectTargets($context), $this->projectTargets($context));
    }

    public function test_resource_identity_comparison_is_stable_and_symmetric(): void
    {
        $a = CurrentResourceReference::of('acme.widget', new \stdClass, 7, 'acme');
        $b = CurrentResourceReference::of('acme.widget', new \stdClass, 7, 'acme');
        $c = CurrentResourceReference::of('acme.widget', new \stdClass, 8, 'acme');

        // Same type + identity + owner is stable and symmetric; a different identity is not.
        $this->assertTrue($a->sameAs($b));
        $this->assertTrue($b->sameAs($a));
        $this->assertFalse($a->sameAs($c));
        $this->assertFalse($c->sameAs($a));
    }

    // ── Fail-closed matrix ───────────────────────────────────────────────────────

    public function test_unknown_resource_kind_with_no_context_yields_null(): void
    {
        // A resource Core cannot classify (not Content/Term) and no explicit context → null.
        $this->assertNull($this->service()->forResource(new \stdClass, 'en'));
    }

    public function test_context_with_no_matching_resolver_yields_null(): void
    {
        $context = new LocalizationContext('nobody.owns.this');

        $this->assertNull($this->service()->forResource(new \stdClass, 'en', $context));
    }

    public function test_missing_translation_yields_null_only_for_the_missing_locale(): void
    {
        app('cms.localization.resolvers')->register($this->englishOnlyResolver());
        $context = new LocalizationContext('acme.enonly');

        $this->assertSame('/acme/en-only', $this->service()->forResource(new \stdClass, 'en', $context));
        $this->assertNull($this->service()->forResource(new \stdClass, 'vi', $context));
    }

    public function test_disabled_locale_yields_null(): void
    {
        app('cms.localization.resolvers')->register($this->widgetResolver());
        $context = new LocalizationContext('acme.widget');

        // 'fr' is a real, normalizable code but is not an active/public locale.
        $this->assertNull($this->service()->forResource(new \stdClass, 'fr', $context));
    }

    public function test_unavailable_switch_target_falls_back_without_throwing(): void
    {
        // No resolver supports this context → every locale target is unavailable and
        // falls back to a localized home URL (never an exception, never an empty URL).
        $targets = $this->switchTargets()->targets(new LocalizationContext('nobody.owns.this'));

        $this->assertNotEmpty($targets);

        foreach ($targets as $target) {
            $this->assertFalse($target->available);
            $this->assertTrue($target->fallbackUsed);
            $this->assertNotSame('', $target->url);
        }
    }

    public function test_conflicting_publication_is_rejected_and_the_first_identity_is_locked(): void
    {
        $context = new CurrentResourceContext;
        $context->publish(CurrentResourceReference::of('page', new \stdClass, 1, 'core'));
        $context->publish(CurrentResourceReference::of('acme.widget', new \stdClass, 9, 'acme'));

        $this->assertSame('page', $context->current()?->type);
        $this->assertTrue($context->hadConflict());
    }

    public function test_unknown_current_resource_type_is_opaque_and_safe(): void
    {
        // An unregistered platform type is accepted (opaque key) and simply resolves
        // to nothing — no per-type Core conditional, no throw, safe degradation.
        $context = new CurrentResourceContext;
        $context->publish(CurrentResourceReference::of('totally.unknown.kind', new \stdClass, 1, 'stranger'));

        $this->assertSame('totally.unknown.kind', $context->current()?->type);
        $this->assertNull($this->service()->forResource(new \stdClass, 'en', new LocalizationContext('totally.unknown.kind')));
    }

    // ── Resolver purity (structural) ─────────────────────────────────────────────

    public function test_core_resolvers_resolve_facts_only(): void
    {
        $forbidden = [
            'CurrentResourceContext',      // must not write the resource authority
            'CurrentResourcePublisher',
            'current_resource',
            'SeoManager',                  // must not own SEO
            'LocalizedUrlGenerator',       // must not generate URLs
            '->render(',                   // must not render responses
        ];

        foreach ($this->resolverFiles() as $file) {
            $source = php_strip_whitespace($file);

            foreach ($forbidden as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $source,
                    basename($file)." must resolve facts only (found forbidden token: {$token}).",
                );
            }
        }
    }

    // ── Reference identity-only ──────────────────────────────────────────────────

    public function test_reference_projection_exposes_identity_only(): void
    {
        $reference = CurrentResourceReference::of('acme.widget', new \stdClass, 42, 'acme');

        // The diagnostics projection is WHAT-only: no url/href/locale/slug/seo surface,
        // and never the backing object.
        $this->assertSame(['type', 'identity', 'owner'], array_keys($reference->toArray()));
    }

    public function test_reference_type_must_not_be_url_shaped(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CurrentResourceReference::of('/acme/gadget', new \stdClass, 1, 'acme');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────────

    /** A resolver available in every locale (prefix strategy handles the '/vi' prefix). */
    private function widgetResolver(): LocalizedResourceResolverContract
    {
        return new class implements LocalizedResourceResolverContract
        {
            public function key(): string
            {
                return 'acme.widget';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(LocalizationContext $context): bool
            {
                return $context->type === 'acme.widget';
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                return new RouteDescriptor(
                    resolverKey: 'acme.widget',
                    canonicalType: 'acme.widget',
                    canonicalId: 1,
                    canonicalPath: '/acme/gadget',
                );
            }
        };
    }

    /** A resolver with a translation in the default locale only. */
    private function englishOnlyResolver(): LocalizedResourceResolverContract
    {
        return new class implements LocalizedResourceResolverContract
        {
            public function key(): string
            {
                return 'acme.enonly';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(LocalizationContext $context): bool
            {
                return $context->type === 'acme.enonly';
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                if ($locale !== 'en') {
                    return null;
                }

                return new RouteDescriptor(
                    resolverKey: 'acme.enonly',
                    canonicalType: 'acme.enonly',
                    canonicalId: 1,
                    canonicalPath: '/acme/en-only',
                );
            }
        };
    }

    /**
     * A stable, comparable projection of the switch targets (drops request-derived
     * volatile fields; keeps the deterministic routing facts).
     *
     * @return array<int, array{locale: string, url: string, available: bool, fallbackUsed: bool}>
     */
    private function projectTargets(LocalizationContext $context): array
    {
        return array_map(static fn (SwitchTarget $target): array => [
            'locale' => $target->locale,
            'url' => $target->url,
            'available' => $target->available,
            'fallbackUsed' => $target->fallbackUsed,
        ], $this->switchTargets()->targets($context));
    }

    /** @return array<int, string> */
    private function resolverFiles(): array
    {
        return glob(base_path('packages/thenguyen/cms-core/src/Localization/Resolvers/*.php')) ?: [];
    }
}
