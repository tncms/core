<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Persistence;

use InvalidArgumentException;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\DictionaryEntry;
use TheNguyen\CMS\Localization\Dictionary\LocalizedRouteSegment;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary;

/**
 * P6.1/P6.2 — builds the immutable Runtime dictionary from composed sources.
 *
 *     Sources → Composer → Loader → immutable RouteSegmentDictionary (singleton)
 *
 * Loaded ONCE at boot. The composer (P6.2) merges all sources — persistence, and later theme/
 * plugin/project — into one canonical payload; the loader validates it BEFORE Runtime creation
 * (Phase F): every key is a {@see RouteKey}, every segment a {@see LocalizedRouteSegment}, every
 * locale non-empty ({@see DictionaryEntry}), and cross-key collisions are rejected at
 * {@see RouteSegmentDictionary} construction. The loader mutates nothing and does no projection
 * (that stays inside RouteSegmentDictionary).
 */
final class RouteDictionaryLoader
{
    public function __construct(private readonly RouteDictionaryComposer $composer) {}

    public function load(): RouteSegmentDictionary
    {
        return new RouteSegmentDictionary($this->entries($this->composer->compose()));
    }

    /**
     * @param  array<string, array<string, string>>  $raw
     * @return array<int, DictionaryEntry>
     */
    private function entries(array $raw): array
    {
        $entries = [];

        foreach ($raw as $key => $localeMap) {
            $routeKey = RouteKey::of((string) $key);

            if (! is_array($localeMap)) {
                throw new InvalidArgumentException("Route dictionary entry [{$key}] must map locales to localized segments.");
            }

            foreach ($localeMap as $locale => $segment) {
                $entries[] = new DictionaryEntry(
                    $routeKey,
                    (string) $locale,
                    LocalizedRouteSegment::of((string) $segment),
                );
            }
        }

        return $entries;
    }
}
