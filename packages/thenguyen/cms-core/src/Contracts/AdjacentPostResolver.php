<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\AdjacentPostViewModel;

/**
 * Resolves the previous (older) and next (newer) published posts adjacent to the
 * current post in the active locale (CORE-BLOG-1). Either side may be null when
 * the current post is first/last. Ordering authority is publication time with a
 * stable id tie-breaker.
 */
interface AdjacentPostResolver
{
    public function previous(Content $post, string $locale): ?AdjacentPostViewModel;

    public function next(Content $post, string $locale): ?AdjacentPostViewModel;
}
