<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * The canonical shape for a locale-aware layout field value
 * (theme-architecture 12, 17 §6):
 *
 *   { "vi": "…", "en": "…" }
 *
 * A localized leaf is a plain locale → string map — no `type`/`value` wrapper.
 * Whether a field is localized is decided by the section schema
 * (`translatable`), never by a marker baked into the stored value. The schema-
 * driven walkers in {@see \TheNguyen\CMS\Services\SectionFieldLocalizer} only
 * ever hand a translatable leaf to this helper, so an array value here is always
 * a locale map (and a plain string / null is an un-migrated value).
 *
 * Layout structure (order, settings, enabled, media) is shared across locales;
 * only text-like field leaves are localized. This helper owns the three leaf
 * operations — detect, resolve (read), and set (write) — so the resolver, the
 * layout editor, and the field localizer never duplicate the shape rules.
 *
 * Backward compatibility is a first-class concern: a plain string is a valid
 * value everywhere (an un-migrated/imported field), and `resolve()` returns it
 * unchanged. A field is migrated to the localized map lazily, on the first edit
 * through the admin, via `set()`.
 */
final class LocalizedValue
{
    /**
     * Whether a value is a localized map ({ "vi": "…", "en": "…" }).
     *
     * Detection is structural: a string-keyed array. Callers only pass
     * translatable leaves, so any array here is a locale map (an empty map is
     * still localized — it resolves to ''). A plain string / null is not.
     */
    public static function isLocalized(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a value to its string for a locale fallback chain. A non-localized
     * value (plain string / null) passes through unchanged. A localized map
     * yields the first non-empty string in the chain, then the first non-empty
     * value in any locale, then '' (never null, never an exception).
     *
     * @param  array<int, string>  $chain  ordered locale codes (current → default → …)
     */
    public static function resolve(mixed $value, array $chain): mixed
    {
        if (! self::isLocalized($value)) {
            return $value;
        }

        $map = self::map($value);

        foreach ($chain as $code) {
            $candidate = $map[$code] ?? null;
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        foreach ($map as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * The editable string for a single locale: the exact locale value when the
     * value is localized (no cross-locale fallback — an untranslated locale shows
     * empty so the editor signals it needs translating), or a plain string as-is
     * (an un-migrated field shows its current text regardless of locale).
     */
    public static function editValue(mixed $value, string $locale): string
    {
        if (self::isLocalized($value)) {
            $candidate = self::map($value)[$locale] ?? '';

            return is_string($candidate) ? $candidate : '';
        }

        return is_string($value) ? $value : '';
    }

    /**
     * Write a locale's string into a localized map, preserving other locales.
     * A plain string `$existing` is migrated under `$default` first (lazy
     * migration), so no previously authored text is lost.
     *
     * @return array<string, string>
     */
    public static function set(mixed $existing, string $locale, ?string $value, string $default): array
    {
        $map = [];

        if (self::isLocalized($existing)) {
            foreach (self::map($existing) as $code => $text) {
                if (is_string($code) && is_string($text)) {
                    $map[$code] = $text;
                }
            }
        } elseif (is_string($existing) && $existing !== '' && $default !== '') {
            $map[$default] = $existing;
        }

        $map[$locale] = $value ?? '';

        return $map;
    }

    /**
     * The locale → string map for a localized value, unwrapping the legacy
     * `{ type: "localized", value: {…} }` wrapper if a value persisted before
     * Phase 4F-B2 is still around (it migrates to the clean shape on next save).
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function map(array $value): array
    {
        if (($value['type'] ?? null) === 'localized' && is_array($value['value'] ?? null)) {
            return $value['value'];
        }

        return $value;
    }
}
