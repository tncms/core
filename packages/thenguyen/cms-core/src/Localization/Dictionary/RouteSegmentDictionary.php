<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

/**
 * P5H.1 — the concrete, bidirectional Route Segment Dictionary.
 *
 * Implements the frozen {@see RouteSegmentDictionaryContract} (P5H.0A). Built ONCE from a set
 * of {@see DictionaryEntry} objects; collisions are rejected eagerly at construction
 * (INV-DICTIONARY-05) via {@see DictionaryCollisionDetector}, never at request time. After
 * construction it is immutable and does pure O(1) map lookups (Phase J — no per-request parsing).
 *
 * Directions (Phase E):
 *   • forward:  segmentFor(key, locale)  → localized segment (null ⇒ caller falls back)
 *   • reverse:  keyFor(segment, locale)  → route key         (null ⇒ no localized segment)
 *
 * Fallback (Phase G): {@see projectSegment()} never fails and never invents a translation — a
 * missing (key, locale) projects to the canonical Route Key value (e.g. "courses" when a locale
 * has no entry), keeping the default locale byte-identical when only non-default entries are stored.
 */
final class RouteSegmentDictionary implements RouteSegmentDictionaryContract
{
    /** @var array<string, array<string, LocalizedRouteSegment>> keyValue => [locale => segment] */
    private array $forward = [];

    /** @var array<string, array<string, RouteKey>> locale => [segmentValue => key] */
    private array $reverse = [];

    /**
     * @param  iterable<int, DictionaryEntry>  $entries
     */
    public function __construct(iterable $entries, ?DictionaryCollisionDetector $detector = null)
    {
        $entries = is_array($entries) ? $entries : iterator_to_array($entries, false);

        ($detector ?? new DictionaryCollisionDetector)->assertNoCollisions($entries);

        foreach ($entries as $entry) {
            $this->forward[$entry->key->value][$entry->locale] = $entry->segment;
            $this->reverse[$entry->locale][$entry->segment->value] = $entry->key;
        }
    }

    public function segmentFor(RouteKey $key, string $locale): ?LocalizedRouteSegment
    {
        return $this->forward[$key->value][$locale] ?? null;
    }

    /** Reverse: the Route Key a localized segment maps to in $locale, or null (Phase E). */
    public function keyFor(string $segment, string $locale): ?RouteKey
    {
        return $this->reverse[$locale][$segment] ?? null;
    }

    /**
     * Forward projection WITH the frozen fallback policy (Phase G): the localized segment when
     * present, otherwise the canonical Route Key value. Never null, never throws.
     */
    public function projectSegment(RouteKey $key, string $locale): string
    {
        return $this->segmentFor($key, $locale)?->value ?? $key->value;
    }

    /**
     * Project a raw base-segment STRING for $locale (P5H.1B wiring convenience). The base string
     * is treated as a Route Key; a base that is not a valid key or has no entry falls back to the
     * base unchanged — so the default locale and non-dictionary segments stay byte-identical.
     */
    public function projectBase(string $base, string $locale): string
    {
        if ($base === '') {
            return $base;
        }

        try {
            $key = RouteKey::of($base);
        } catch (\Throwable) {
            return $base;
        }

        return $this->projectSegment($key, $locale);
    }

    /**
     * The non-default (locale => localized segment) map for a key — used to GENERATE reverse
     * route registrations from the dictionary (never hardcoded).
     *
     * @return array<string, string>
     */
    public function localeSegments(RouteKey $key): array
    {
        return array_map(
            static fn (LocalizedRouteSegment $segment): string => $segment->value,
            $this->forward[$key->value] ?? [],
        );
    }

    /** @return array<int, string> the locales that carry at least one entry (diagnostics). */
    public function locales(): array
    {
        return array_keys($this->reverse);
    }
}
