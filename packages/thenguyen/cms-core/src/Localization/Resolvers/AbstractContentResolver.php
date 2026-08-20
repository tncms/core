<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Services\PermalinkManager;

/**
 * CORE-L10N.1B — shared logic for the built-in Content (page/post) resolvers.
 *
 * It reproduces the legacy per-locale content path exactly: the locale's stored slug
 * ({@see \TheNguyen\CMS\Models\Content::localeSlug()}) combined with the permalink base for the
 * content type, prefix-free. A missing locale slug yields null (no fabricated translation).
 */
abstract class AbstractContentResolver implements LocalizedResourceResolverContract
{
    public function __construct(protected readonly PermalinkManager $permalinks) {}

    /** The content type this resolver owns ('page' | 'post'). */
    abstract protected function type(): string;

    public function key(): string
    {
        return 'cms.'.$this->type();
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(LocalizationContext $context): bool
    {
        return $context->type === $this->type()
            && $context->content !== null
            && $context->content->type === $this->type();
    }

    public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
    {
        $content = $context->content;

        if ($content === null) {
            return null;
        }

        $slug = $content->localeSlug($locale);

        if ($slug === null) {
            return null;
        }

        // P5H.1B: project the permalink base through the Route Segment Dictionary (e.g. the
        // 'post'/'page' base → 'bai-viet'/'trang' in vi). The default locale projects to the
        // base unchanged (byte-identical); an unknown base falls back to itself.
        $base = $this->permalinks->contentBase((string) $content->type);
        $base = $base !== null && $base !== '' && $locale !== app('cms.language')->defaultCode()
            ? app('cms.localization.dictionary')->projectBase($base, $locale)
            : $base;
        $path = $base !== null && $base !== '' ? '/'.$base.'/'.$slug : '/'.$slug;

        return new RouteDescriptor(
            resolverKey: $this->key(),
            canonicalType: (string) $content->type,
            canonicalId: $content->id,
            canonicalPath: $path,
        );
    }
}
