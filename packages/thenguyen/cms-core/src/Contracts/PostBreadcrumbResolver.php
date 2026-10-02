<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\View\BreadcrumbItemViewModel;

/**
 * Resolves the semantic breadcrumb trail for a post-detail page (CORE-BLOG-1).
 * Core owns the policy (localized Home, optional primary category, current post);
 * the theme renders the returned list and never parses URLs or queries taxonomy.
 */
interface PostBreadcrumbResolver
{
    /**
     * @return array<int, BreadcrumbItemViewModel>
     */
    public function forPost(Content $post, string $locale): array;
}
