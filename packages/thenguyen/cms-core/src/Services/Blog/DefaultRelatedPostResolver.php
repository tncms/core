<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Blog;

use Illuminate\Support\Collection;
use TheNguyen\CMS\Contracts\RelatedPostResolver;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\PostCardViewModel;

/**
 * Default related-post resolver (CORE-BLOG-1).
 *
 * Reuses the shared {@see RelatedContentSelector} relation algorithm (the same
 * one the Page Builder related source uses) over a published, locale-scoped,
 * eager-loaded post query, then projects matches into safe PostCardViewModels.
 *
 * Baseline policy = the selector's "automatic" mode (posts sharing any category
 * OR tag with the current post, excluding itself), ordered newest-first with a
 * stable id tie-breaker and a small bounded limit. Weighted category-over-tag
 * scoring is a documented future enhancement; keeping the baseline identical to
 * the Page Builder engine guarantees one shared, parity-tested algorithm.
 */
class DefaultRelatedPostResolver implements RelatedPostResolver
{
    private const LIMIT_MAX = 24;

    public function __construct(
        private readonly RelatedContentSelector $selector,
        private readonly PostTaxonomyProjector $taxonomies,
    ) {}

    /**
     * @return Collection<int, PostCardViewModel>
     */
    public function forPost(Content $post, string $locale, int $limit = 3): Collection
    {
        $limit = max(1, min($limit, self::LIMIT_MAX));

        $query = Content::query()
            ->posts()
            ->published()
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->whereNotNull('slug'))
            ->with(['translations', 'terms.translations', 'terms.taxonomy']);

        // No match population → empty section, no fabricated content.
        if (! $this->selector->apply($query, $post, 'automatic')) {
            return new Collection;
        }

        $posts = $query
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $cards = new Collection;

        foreach ($posts as $candidate) {
            $card = PostCardViewModel::fromContent(
                $candidate,
                $locale,
                $this->taxonomies->primaryCategory($candidate, $locale),
            );

            if ($card !== null) {
                $cards->push($card);
            }
        }

        return $cards;
    }
}
