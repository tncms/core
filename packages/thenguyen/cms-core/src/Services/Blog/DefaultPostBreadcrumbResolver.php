<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use TheNguyen\CMS\Contracts\PostBreadcrumbResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\LanguageManager;
use TheNguyen\CMS\View\BreadcrumbItemViewModel;

/**
 * Default post-detail breadcrumb policy (CORE-BLOG-1):
 *
 *   Home → [primary category] → Current post
 *
 * - Home: a localized, locale-aware link to the site root.
 * - Primary category: the post's first resolvable category in the active locale
 *   (deterministic; Core owns this "first category" policy). Omitted entirely
 *   when the post has no locale-resolvable category — never a broken link, never
 *   a theme-side guess.
 * - Current post: the localized title as the non-linked current item.
 *
 * Labels are plain text (themes escape on output); URLs come from the canonical
 * locale-aware helpers. BreadcrumbList/JSON-LD is intentionally NOT emitted here
 * — SEO structured-data ownership stays with SeoManager.
 */
class DefaultPostBreadcrumbResolver implements PostBreadcrumbResolver
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly PostTaxonomyProjector $taxonomies,
    ) {}

    /**
     * @return array<int, BreadcrumbItemViewModel>
     */
    public function forPost(Content $post, string $locale): array
    {
        $items = [
            new BreadcrumbItemViewModel(
                label: core_trans('Home', [], $locale),
                url: $this->languages->localizedUrl($locale, '/'),
                isCurrent: false,
            ),
        ];

        $primaryCategory = $this->taxonomies->primaryCategory($post, $locale);

        if ($primaryCategory !== null) {
            $items[] = new BreadcrumbItemViewModel(
                label: $primaryCategory->label,
                url: $primaryCategory->url,
                isCurrent: false,
            );
        }

        $items[] = new BreadcrumbItemViewModel(
            label: $post->translatedTitle($locale),
            url: null,
            isCurrent: true,
        );

        return $items;
    }
}
