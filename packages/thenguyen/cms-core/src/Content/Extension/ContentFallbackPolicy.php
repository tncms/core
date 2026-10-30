<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * What Core does with a managed content item while its owner plugin is
 * inactive or missing (owner decision D-2). Applied identically to the single
 * page and to every discovery surface.
 */
enum ContentFallbackPolicy: string
{
    case CoreRender = 'core_render';      // serve the Core content (Core SEO, no override)
    case Hide404 = 'hide_404';            // 404 and hidden from discovery (default for plugin-created content)
    case Temporary503 = 'temporary_503';  // 503 + Retry-After and hidden from discovery
}
