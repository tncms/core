<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use Illuminate\Database\Eloquent\Builder;
use TheNguyen\CMS\Models\Content;

/**
 * Shared related-content relation selector (CORE-BLOG-1).
 *
 * This is the single source of the "related posts" relation algorithm. It was
 * extracted verbatim from {@see \TheNguyen\CMS\Services\SectionDataProvider}'s
 * former private `applyRelated()` so the Page Builder related source and the
 * frontend post-detail related resolver share one implementation — no
 * duplication, identical behavior.
 *
 * It only narrows the WHERE/relation predicate of a caller-owned, already
 * published+locale-scoped post query. Ordering, offset, limit and eager-loading
 * remain the caller's responsibility (preserving each caller's existing query
 * assembly and behavior).
 */
class RelatedContentSelector
{
    /**
     * Restrict $query to posts related to the current post per $mode. Returns
     * false (→ caller should render nothing) when the chosen relation has nothing
     * to match on. Never queries from Blade — the current post is supplied by the
     * caller.
     *
     * Modes: current_post_only | same_category | same_tags | same_author |
     * automatic (shared category OR tag). Unknown modes fall through to automatic.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     */
    public function apply(Builder $query, Content $currentPost, string $mode = 'automatic'): bool
    {
        if ($mode === 'current_post_only') {
            $query->whereKey([$currentPost->getKey()]);

            return true;
        }

        // A post is never "related" to itself.
        $query->whereKeyNot($currentPost->getKey());

        $categoryIds = $this->postTermIds($currentPost, 'category');
        $tagIds = $this->postTermIds($currentPost, 'tag');

        switch ($mode) {
            case 'same_category':
                if ($categoryIds === []) {
                    return false;
                }
                $this->whereInTaxonomy($query, 'category', $categoryIds);

                return true;

            case 'same_tags':
                if ($tagIds === []) {
                    return false;
                }
                $this->whereInTaxonomy($query, 'tag', $tagIds);

                return true;

            case 'same_author':
                if ($currentPost->author_id === null) {
                    return false;
                }
                $query->where('author_id', $currentPost->author_id);

                return true;

            case 'automatic':
            default:
                // Posts sharing any category OR tag with the current post.
                if ($categoryIds === [] && $tagIds === []) {
                    return true; // degrade to "latest excluding self"
                }

                $query->where(function ($q) use ($categoryIds, $tagIds): void {
                    if ($categoryIds !== []) {
                        $q->orWhereHas('terms', static fn ($t) => $t
                            ->whereHas('taxonomy', static fn ($x) => $x->where('type', 'category'))
                            ->whereIn('cms_terms.id', $categoryIds));
                    }

                    if ($tagIds !== []) {
                        $q->orWhereHas('terms', static fn ($t) => $t
                            ->whereHas('taxonomy', static fn ($x) => $x->where('type', 'tag'))
                            ->whereIn('cms_terms.id', $tagIds));
                    }
                });

                return true;
        }
    }

    /**
     * Narrow $query to posts having at least one term of $type within $termIds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<int, int>  $termIds
     */
    private function whereInTaxonomy(Builder $query, string $type, array $termIds): void
    {
        $query->whereHas('terms', static fn ($q) => $q
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $type))
            ->whereIn('cms_terms.id', $termIds));
    }

    /**
     * The current post's term ids for a taxonomy type.
     *
     * @return array<int, int>
     */
    private function postTermIds(Content $post, string $type): array
    {
        return $post->terms()
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $type))
            ->pluck('cms_terms.id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }
}
