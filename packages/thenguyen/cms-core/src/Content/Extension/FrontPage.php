<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use TheNguyen\CMS\Models\Content;

/**
 * The site's front page: the configured static front page or the
 * conventional home page (CORE-CONTENT-EXT-R1 D-8). It can never become
 * plugin-owned — not by creation, adoption or operator transfer — so `/` can
 * never bypass Core (CORE-UNIFIED-OWNERSHIP-R1).
 */
final class FrontPage
{
    public const CONVENTIONAL_HOME_SLUG = 'trang-chu';

    /** A page that would become the conventional home page when created. */
    public static function isConventionalHome(string $type, string $slug, string $locale): bool
    {
        $default = app('cms.language')->defaultCode();

        return $type === 'page' && $slug === self::CONVENTIONAL_HOME_SLUG && ($locale === '' || $locale === $default);
    }

    public static function is(int $contentId, string $type): bool
    {
        if ($type !== 'page') {
            return false;
        }

        if ((int) settings('reading.homepage_page_id', 0) === $contentId) {
            return true;
        }

        $home = Content::query()->where('type', 'page')
            ->whereHas('translations', static fn ($q) => $q->where('slug', self::CONVENTIONAL_HOME_SLUG)->where('locale', app('cms.language')->defaultCode()))
            ->value('id');

        return $home !== null && (int) $home === $contentId;
    }
}
