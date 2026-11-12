<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

/**
 * The fixture's public URL families: one sitemap type each, a localized path
 * segment per locale. Routes and the sitemap provider both read this table, so
 * a listed URL is always a URL the plugin serves.
 */
final class Families
{
    /** @var array<string, array{family: string, lottery?: bool, segments: array<string, string>}> */
    public const TYPES = [
        'kb_articles' => ['family' => 'kb_article', 'segments' => ['vi' => 'kien-thuc/bai-viet', 'en' => 'knowledge/articles']],
        'kb_categories' => ['family' => 'kb_category', 'segments' => ['vi' => 'kien-thuc/chu-de', 'en' => 'knowledge/topics']],
        'lottery_draws' => ['family' => 'lottery_draw', 'lottery' => true, 'segments' => ['vi' => 'xo-so', 'en' => 'lottery-results']],
        'ec_products' => ['family' => 'ec_product', 'segments' => ['vi' => 'cua-hang/san-pham', 'en' => 'shop/products']],
        'ec_categories' => ['family' => 'ec_category', 'segments' => ['vi' => 'cua-hang/danh-muc', 'en' => 'shop/categories']],
    ];

    public static function segment(string $type, string $locale): ?string
    {
        return self::TYPES[$type]['segments'][$locale] ?? null;
    }

    /** Path below the locale prefix, or null when the item has no URL in $locale. */
    public static function path(string $type, Item $item, string $locale): ?string
    {
        $segment = self::segment($type, $locale);
        $slug = $item->getAttribute('slug_'.$locale);

        if ($segment === null || ! is_string($slug) || $slug === '') {
            return null;
        }

        if (self::TYPES[$type]['lottery'] ?? false) {
            return $segment.'/'.$item->region.'/'.$item->draw_date->format('d-m-Y').'.html';
        }

        return $segment.'/'.$slug;
    }

    /** Absolute URL through Core's locale authority (prefix rules included). */
    public static function url(string $type, Item $item, string $locale): ?string
    {
        $path = self::path($type, $item, $locale);

        return $path === null ? null : url(app('cms.language')->localizedUrl($locale, '/'.$path));
    }
}
