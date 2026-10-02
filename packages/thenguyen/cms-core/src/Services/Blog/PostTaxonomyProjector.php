<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\View\TaxonomyLinkViewModel;

/**
 * Projects a post's attached taxonomy terms into safe TaxonomyLinkViewModel
 * lists (CORE-BLOG-1). Strictly per-locale: only terms with a resolvable name
 * and canonical URL in the requested locale are included — no cross-locale
 * fallback, no raw term model, no "Term #id" placeholder. Reads already-loaded
 * relations when present so the post-detail render stays query-bounded.
 */
class PostTaxonomyProjector
{
    /**
     * @return array<int, TaxonomyLinkViewModel>
     */
    public function categories(Content $post, string $locale): array
    {
        return $this->links($post, 'category', $locale);
    }

    /**
     * @return array<int, TaxonomyLinkViewModel>
     */
    public function tags(Content $post, string $locale): array
    {
        return $this->links($post, 'tag', $locale);
    }

    /**
     * The post's primary (first, deterministic) category link, or null.
     */
    public function primaryCategory(Content $post, string $locale): ?TaxonomyLinkViewModel
    {
        return $this->categories($post, $locale)[0] ?? null;
    }

    /**
     * @return array<int, TaxonomyLinkViewModel>
     */
    private function links(Content $post, string $type, string $locale): array
    {
        $terms = $post->relationLoaded('terms')
            ? $post->terms
            : $post->terms()->with(['taxonomy', 'translations'])->get();

        // Deterministic order independent of pivot/load order.
        $ordered = $terms->sort(static function (Term $a, Term $b): int {
            return [(int) ($a->sort_order ?? 0), (int) $a->getKey()]
                <=> [(int) ($b->sort_order ?? 0), (int) $b->getKey()];
        })->values();

        $links = [];

        foreach ($ordered as $term) {
            if (($term->taxonomy->type ?? null) !== $type) {
                continue;
            }

            $label = $term->localeName($locale);

            if (! is_string($label) || $label === '') {
                continue;
            }

            $url = term_url($term, $locale);

            if ($url === '#') {
                continue;
            }

            $links[] = new TaxonomyLinkViewModel($type, $label, $url);
        }

        return $links;
    }
}
