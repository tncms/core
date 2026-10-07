<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Canonical Theme Options groups (CORE-THEME-OPTIONS-UX-1, v1.0.0-beta.7.1.31).
 *
 * A theme option *section* may declare a `group` naming the admin tab it belongs
 * to. Core — not the theme — owns the tab identity (label + icon + order) so the
 * Theme Options UI stays consistent across every theme, exactly like CMS
 * Settings. A section that declares no group, or an unknown one, falls back to
 * {@see GENERAL}. This is purely an admin-UI grouping contract: it never changes
 * stored setting keys, values, or the public `theme_option()` read path.
 */
final class ThemeOptionGroup
{
    public const GENERAL = 'general';
    public const BRAND = 'brand';
    public const COLORS = 'colors';
    public const LAYOUT = 'layout';
    public const MEDIA = 'media';
    public const SEO = 'seo';
    public const CODE = 'code';
    public const ADVANCED = 'advanced';

    /** The tab a section falls into when it declares no (or an unknown) group. */
    public const FALLBACK = self::GENERAL;

    /** The tab that always hosts the built-in Custom CSS editors. */
    public const CUSTOM_CSS = self::CODE;

    /**
     * Ordered canonical registry: group key => [English label, heroicon].
     * Order here is the order tabs appear in the admin UI.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const GROUPS = [
        self::GENERAL => ['General', 'heroicon-o-adjustments-horizontal'],
        self::BRAND => ['Brand', 'heroicon-o-identification'],
        self::COLORS => ['Colors', 'heroicon-o-swatch'],
        self::LAYOUT => ['Layout', 'heroicon-o-view-columns'],
        self::MEDIA => ['Media', 'heroicon-o-photo'],
        self::SEO => ['SEO & Social', 'heroicon-o-megaphone'],
        self::CODE => ['Custom CSS & Code', 'heroicon-o-code-bracket'],
        self::ADVANCED => ['Advanced', 'heroicon-o-wrench-screwdriver'],
    ];

    /**
     * Canonical group keys in tab order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::GROUPS[$key]);
    }

    /**
     * Resolve a declared group to a known key, falling back to GENERAL for a
     * missing/empty/unknown value. Comparison is case-insensitive on a trimmed
     * value so "Colors" and " colors " both resolve to `colors`.
     */
    public static function normalize(mixed $group): string
    {
        if (! is_string($group)) {
            return self::FALLBACK;
        }

        $key = strtolower(trim($group));

        return self::isKnown($key) ? $key : self::FALLBACK;
    }

    /**
     * The canonical English label for a group (localize at the UI boundary with
     * tn_trans()). Unknown keys yield the GENERAL label.
     */
    public static function label(string $key): string
    {
        return (self::GROUPS[$key] ?? self::GROUPS[self::FALLBACK])[0];
    }

    public static function icon(string $key): string
    {
        return (self::GROUPS[$key] ?? self::GROUPS[self::FALLBACK])[1];
    }
}
