<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use Illuminate\Support\Collection;
use TheNguyen\CMS\Models\Content;

/**
 * Resolves related published posts for a post-detail page as safe post cards
 * (CORE-BLOG-1). Uses the same relation-selection algorithm the Page Builder
 * related source uses (shared via RelatedContentSelector). Returns an empty
 * collection when there is nothing to show; never a raw model.
 *
 * @method \Illuminate\Support\Collection<int, \TheNguyen\CMS\View\PostCardViewModel> forPost(Content $post, string $locale, int $limit = 3)
 */
interface RelatedPostResolver
{
    /**
     * @return Collection<int, \TheNguyen\CMS\View\PostCardViewModel>
     */
    public function forPost(Content $post, string $locale, int $limit = 3): Collection;
}
