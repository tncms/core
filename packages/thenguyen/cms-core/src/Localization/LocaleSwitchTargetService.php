<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\Contracts\PublicLocaleContextContract;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary;

/**
 * CORE-L10N.1B — the ONE authority for language-switch target generation.
 *
 * Pipeline: request context → resolver registry → resolver → RouteDescriptor → active strategy
 * → LocalizedUrlGenerator → {@see SwitchTarget}. Both {@see \language_switcher()} and
 * {@see \TheNguyen\CMS\Services\SeoManager} consume this single service, so the switcher,
 * canonical, and hreflang all flow through one pipeline. It contains no per-resource-type
 * branching — resource knowledge lives entirely in resolvers.
 *
 * For a locale with no eligible translation (or a context no resolver supports), the target is
 * marked unavailable and its URL falls back to the localized home under the active strategy:
 * the switcher renders that home link (legacy behaviour), while SEO skips unavailable targets.
 */
final class LocaleSwitchTargetService
{
    public function __construct(
        private readonly LanguageConfigurationContract $config,
        private readonly LocalizedResourceResolverRegistry $registry,
        private readonly PublicLocaleContextContract $publicLocale,
        private readonly RouteSegmentDictionary $dictionary,
        private readonly Container $app,
    ) {}

    /**
     * The normalized switch targets for $context, one per enabled locale (in configured order).
     *
     * @return array<int, SwitchTarget>
     */
    public function targets(LocalizationContext $context): array
    {
        $strategy = $this->app->make(LocalizationStrategyContract::class);
        $resolver = $this->registry->select($context);
        $request = request();
        $current = $this->publicLocale->current();
        $home = new RouteDescriptor('cms.home', 'home', null, canonicalPath: '/');

        // Generic route preservation: when the context carries NO translatable resource
        // (a listing, cart, checkout, search, or custom/theme/plugin route) yet the request
        // is not the home path, switch locale on the SAME route instead of dropping the
        // user on the localized home. The canonical route identity is recovered via the
        // Route Segment Dictionary (reverse: localized segment → route key), then projected
        // to each target locale (forward: key → localized segment) — so switching is
        // bidirectional (/products ↔ /vi/san-pham) with no string replacement. Resource
        // contexts — including a resource whose target translation is a draft — keep the
        // resolver/home behaviour so the switcher never links to an untranslated resource
        // slug. These targets stay available=false, so SEO (which skips unavailable
        // targets) is unaffected.
        $genericBase = $context->resource() === null ? $this->genericRouteBase($request) : null;

        $targets = [];

        foreach ($this->config->allMetadata() as $meta) {
            $locale = $meta->code;

            if ($genericBase !== null) {
                // Forward-project the canonical route key to this locale's segment (falls back
                // to the canonical base for locales/keys without a dictionary entry), preserving
                // the remaining route parameters (slug/id/…). One prefix authority applies the
                // locale prefix.
                $targetBase = $genericBase['key'] instanceof RouteKey
                    ? $this->dictionary->projectSegment($genericBase['key'], $this->config->normalize($locale))
                    : $genericBase['base'];

                $path = '/' . implode('/', array_merge([$targetBase], $genericBase['rest']));
                $descriptor = new RouteDescriptor('core.current_route', 'route', null, canonicalPath: $path);

                $url = $strategy->url($descriptor, $locale, $request);
                $available = false;
                $fallbackUsed = true;
                $canonicalType = null;
                $canonicalId = null;
            } else {
                $descriptor = $resolver?->descriptorFor($context, $locale);

                if ($descriptor !== null) {
                    $url = $strategy->url($descriptor, $locale, $request);
                    $available = true;
                    $fallbackUsed = false;
                    $canonicalType = $descriptor->canonicalType;
                    $canonicalId = $descriptor->canonicalId;
                } else {
                    // Missing translation (e.g. a draft): never link to the untranslated
                    // resource slug — fall back to the localized home.
                    $url = $strategy->url($home, $locale, $request);
                    $available = false;
                    $fallbackUsed = true;
                    $canonicalType = null;
                    $canonicalId = null;
                }
            }

            $targets[] = new SwitchTarget(
                locale: $locale,
                label: $meta->nativeName,
                nativeLabel: $meta->nativeName,
                active: $locale === $current,
                available: $available,
                url: $url,
                method: $strategy->switchMethod(),
                hreflang: $locale,
                fallbackUsed: $fallbackUsed,
                canonicalType: $canonicalType,
                canonicalId: $canonicalId,
                resolverKey: $resolver?->key(),
                strategyKey: $strategy->key(),
                action: $strategy->switchAction($locale, $url, $request),
                direction: $meta->direction,
                flag: $meta->flag,
            );
        }

        return $targets;
    }

    /** The resolver key selected for $context (for diagnostics), or null when none supports it. */
    public function selectedResolverKey(LocalizationContext $context): ?string
    {
        return $this->registry->select($context)?->key();
    }

    /**
     * The canonical route identity of the CURRENT request (module-agnostic), recovered
     * through the Route Segment Dictionary so locale switching is bidirectional.
     *
     * It drops a leading locale segment (only when it is an enabled locale — never a
     * hardcoded prefix), then reverse-maps the base segment to its canonical route key
     * via {@see RouteSegmentDictionary::keyFor()} in the CURRENT locale; a base that is
     * not a localized segment is treated as its own key. The remaining segments (slug,
     * id, nested params) are preserved verbatim. Callers forward-project the key to each
     * target locale. Returns null for the home path (or a bare locale), leaving the
     * localized home as the final fallback.
     *
     * @return array{key: RouteKey|null, base: string, rest: list<string>}|null
     */
    private function genericRouteBase(?object $request): ?array
    {
        if (! $request instanceof Request) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $request->path()), static fn (string $s): bool => $s !== ''));

        if ($segments !== [] && $this->config->isEnabled($segments[0])) {
            array_shift($segments);
        }

        if ($segments === []) {
            return null;
        }

        $base = array_shift($segments);
        $current = $this->publicLocale->current();

        // Reverse the (possibly localized) base to its canonical route key. When the base is
        // not a localized segment, use it as its own key; when it is not even a valid key
        // (e.g. an unnamed/dynamic first segment), key stays null → prefix-only preservation.
        $key = $this->dictionary->keyFor($base, $current);

        if ($key === null) {
            try {
                $key = RouteKey::of($base);
            } catch (\Throwable) {
                $key = null;
            }
        }

        return ['key' => $key, 'base' => $base, 'rest' => $segments];
    }
}
