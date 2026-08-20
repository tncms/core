<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Services\PermalinkManager;

/**
 * CORE-L10N.1B — shared logic for the built-in Term (category/tag) resolvers.
 *
 * It reproduces the legacy per-locale term path exactly: the locale's stored slug
 * ({@see \TheNguyen\CMS\Models\Term::localeSlug()}) combined with the permalink base for the
 * taxonomy type, prefix-free. A missing locale slug yields null.
 */
abstract class AbstractTermResolver implements LocalizedResourceResolverContract
{
    public function __construct(protected readonly PermalinkManager $permalinks) {}

    /** The taxonomy type this resolver owns ('category' | 'tag'). */
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
        return $context->type === $this->type() && $context->term !== null;
    }

    public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
    {
        $term = $context->term;

        if ($term === null) {
            return null;
        }

        $slug = $term->localeSlug($locale);

        if ($slug === null) {
            return null;
        }

        // P5H.1B: project the taxonomy permalink base through the Route Segment Dictionary
        // (e.g. 'category' → 'danh-muc', 'tag' → 'the' in vi). Default locale unchanged.
        $base = $this->permalinks->termBase($this->type());
        $base = $base !== null && $base !== '' && $locale !== app('cms.language')->defaultCode()
            ? app('cms.localization.dictionary')->projectBase($base, $locale)
            : $base;
        $path = $base !== null && $base !== '' ? '/'.$base.'/'.$slug : '/'.$slug;

        return new RouteDescriptor(
            resolverKey: $this->key(),
            canonicalType: $this->type(),
            canonicalId: $term->id,
            canonicalPath: $path,
        );
    }
}
