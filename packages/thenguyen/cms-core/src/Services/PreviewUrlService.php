<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-L10N.1B (Phase P3.1) — the ONE canonical, locale-aware Preview URL
 * producer for every Page/Post admin surface (editor launcher + list tables).
 *
 * It owns NO localization policy. Resource resolution and localized frontend
 * route construction are delegated to the Localization Platform (the resolver
 * registry + {@see LocalizedUrlGenerator}); signed-preview identity/expiry is
 * delegated to the {@see PreviewManager}. This service only decides, for a
 * canonical resource + an EXPLICIT locale, which of those two the caller needs
 * and stitches them together:
 *
 *   published + translated in $locale  → the real public localized URL
 *                                        (via the platform; unsigned, public)
 *   draft / scheduled / untranslated   → a signed preview URL that carries
 *                                        $locale (signed in) and is rendered by
 *                                        the locale-aware preview renderer
 *
 * It NEVER returns '#'. When a URL genuinely cannot be produced (unknown or
 * disabled locale, or no resolver handles the resource) it returns null so the
 * caller can render a disabled/unavailable action instead of a dead anchor.
 */
final class PreviewUrlService
{
    public function __construct(
        private readonly LocalizedContentUrlService $contentUrls,
        private readonly LanguageConfigurationContract $config,
        private readonly PreviewManager $preview,
    ) {}

    /**
     * The preview URL for $content in $locale (defaults to the platform default
     * locale). Returns null — never '#' — when it cannot be produced.
     */
    public function forContent(Content $content, ?string $locale = null): ?string
    {
        $locale = $this->config->normalize($locale ?? $this->config->defaultLocale());

        if (! $this->config->isEnabled($locale)) {
            return null;
        }

        // Published + translated → the canonical public URL, built through the
        // ONE resource→public-URL service. This service applies no prefix/slug
        // policy of its own.
        if ((string) $content->status === 'published') {
            $public = $this->contentUrls->absoluteForResource($content, $locale);

            if ($public !== null) {
                return $public;
            }
        }

        // Draft / scheduled / published-without-this-translation → a signed
        // preview URL carrying the locale. The renderer localizes the output.
        return $this->preview->temporaryUrl($content, locale: $locale);
    }
}
