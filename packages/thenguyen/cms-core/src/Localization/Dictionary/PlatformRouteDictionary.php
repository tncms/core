<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

/**
 * P5H.1 — the initial Platform Route Dictionary data + factory.
 *
 * The single source of truth for localized static route segments (Phase B/C). Only NON-DEFAULT
 * locale entries are stored: the default locale projects to the canonical Route Key value via
 * {@see RouteSegmentDictionary::projectSegment()}, so default-locale URLs stay byte-identical
 * and the dictionary is purely additive per locale.
 *
 * Deliberate deviations from the P5H.1 brief, required to satisfy the FROZEN collision contract
 * (INV-DICTIONARY-05) and default byte-identity:
 *   • `product-category` (vi) is `danh-muc-san-pham`, NOT `danh-muc`: the brief mapped BOTH the
 *     Core `category` key and the Ecommerce `product-category` key to `danh-muc` in vi, which is a
 *     registration collision (two keys → one (locale, segment)). `category` keeps `danh-muc`.
 *   • Default (en) segments are the CURRENT route segments (identity), so no default URL changes.
 *
 * Adding a locale or a key is a pure data edit here — no Runtime change (INV-DICTIONARY-06).
 */
final class PlatformRouteDictionary
{
    /**
     * key => [ locale => localized segment ]. Non-default locales only.
     *
     * @var array<string, array<string, string>>
     */
    private const ENTRIES = [
        // Ecommerce.
        'products' => ['vi' => 'san-pham', 'de' => 'produkte', 'fr' => 'produits', 'ja' => '商品'],
        'product-category' => ['vi' => 'danh-muc-san-pham', 'de' => 'produkt-kategorien'],
        'brand' => ['vi' => 'thuong-hieu', 'de' => 'marken'],

        // Core CMS.
        'category' => ['vi' => 'danh-muc', 'de' => 'kategorien'],
        'tag' => ['vi' => 'the'],
        'page' => ['vi' => 'trang'],
        'post' => ['vi' => 'bai-viet'],
        'author' => ['vi' => 'tac-gia'],
        'search' => ['vi' => 'tim-kiem'],

        // Documentation.
        'docs' => ['vi' => 'tai-lieu'],
        'doc-category' => ['vi' => 'chu-de'],
    ];

    /** Build the immutable, collision-checked dictionary (load once — bind as a singleton). */
    public static function make(): RouteSegmentDictionary
    {
        return new RouteSegmentDictionary(self::entries());
    }

    /**
     * The frozen mappings as raw data (`key => [locale => segment]`) — the SEED used to populate
     * persistent storage and the fallback default when no persisted dictionary exists (P6.1).
     *
     * @return array<string, array<string, string>>
     */
    public static function data(): array
    {
        return self::ENTRIES;
    }

    /**
     * The canonical entry set as value objects (validated).
     *
     * @return array<int, DictionaryEntry>
     */
    public static function entries(): array
    {
        $entries = [];

        foreach (self::ENTRIES as $key => $localeMap) {
            $routeKey = RouteKey::of($key);

            foreach ($localeMap as $locale => $segment) {
                $entries[] = new DictionaryEntry($routeKey, $locale, LocalizedRouteSegment::of($segment));
            }
        }

        return $entries;
    }
}
