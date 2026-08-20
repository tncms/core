<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\LocalizedResourceResolverRegistry;
use TheNguyen\CMS\Localization\LocalizedUrlGenerator;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-L10N.1B (Phase P3.2) — the ONE canonical resource-to-public-URL
 * application service.
 *
 * It is the single seam every consumer uses to turn a canonical resource +
 * target locale into its public localized URL. It owns NO routing policy of its
 * own: it composes the frozen platform pieces —
 *
 *   resource → LocalizationContext → LocalizedResourceResolverRegistry
 *            → LocalizedResourceResolver → RouteDescriptor
 *            → LocalizedUrlGenerator (path → localized → absolute)
 *
 * The prefix/slug/route policy lives entirely in the resolvers + generator, so
 * there is exactly one place that decides a localized path. Compatibility
 * helpers ({@see content_url()}, {@see term_url()}) and higher-level services
 * ({@see PreviewUrlService}, SEO, menus, sitemap) delegate here rather than
 * rebuilding a path — no second authority may exist.
 *
 * Failure model (documented, single): a resource with no resolver, no
 * translation in $locale, or a disabled/unknown locale yields NULL. It NEVER
 * returns '#', never mutates or persists locale, and never chooses the admin UI
 * locale implicitly — the caller passes the locale explicitly. Compatibility
 * facades map the null to their own legacy sentinel ('#'); new code consumes the
 * null directly.
 */
final class LocalizedContentUrlService
{
    public function __construct(
        private readonly LocalizedResourceResolverRegistry $resolvers,
        private readonly LocalizedUrlGenerator $generator,
        private readonly LanguageConfigurationContract $config,
    ) {}

    /**
     * The root-relative localized public path for $resource in $locale, or null.
     *
     * Root-relative is the platform's normalized form (e.g. '/blog/x' or
     * '/vi/blog/x'); it is byte-identical to the legacy helper output. Use
     * {@see absoluteForResource()} when an absolute URL is required.
     */
    public function forResource(object $resource, string $locale, ?LocalizationContext $context = null): ?string
    {
        $locale = $this->config->normalize($locale);

        if (! $this->config->isEnabled($locale)) {
            return null;
        }

        $context ??= $this->contextFor($resource);

        if ($context === null) {
            return null;
        }

        $resolver = $this->resolvers->select($context);

        if ($resolver === null) {
            return null;
        }

        $descriptor = $resolver->descriptorFor($context, $locale);

        if ($descriptor === null) {
            return null;
        }

        return $this->generator->localized($this->generator->path($descriptor), $locale);
    }

    /**
     * The absolute localized public URL for $resource in $locale, or null.
     */
    public function absoluteForResource(object $resource, string $locale, ?LocalizationContext $context = null): ?string
    {
        $path = $this->forResource($resource, $locale, $context);

        return $path === null ? null : $this->generator->absolute($path);
    }

    /**
     * Derive the localization context from a Core resource. Unknown resource
     * kinds (a caller must then pass an explicit context) yield null.
     */
    private function contextFor(object $resource): ?LocalizationContext
    {
        if ($resource instanceof Content) {
            return new LocalizationContext((string) $resource->type, $resource);
        }

        if ($resource instanceof Term) {
            $type = $resource->taxonomy?->type ?? 'category';

            return new LocalizationContext($type, null, $resource);
        }

        return null;
    }
}
