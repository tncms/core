<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Seo;

use InvalidArgumentException;
use TheNguyen\CMS\Content\Extension\RobotsRestriction;

/**
 * Per-item robots restriction for Core posts, pages, categories and tags
 * (CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1), stored in `seo_robots`.
 *
 * NULL = inherit the site policy. Otherwise one of the RobotsRestriction values:
 * the item can only ADD noindex and/or nofollow to what it inherits, so it never
 * loosens the site default or the site-wide "discourage search engines" toggle.
 * A noindex item stays reachable over HTTP and is left out of the sitemap.
 */
final class ResourceRobotsPolicy
{
    public const COLUMN = 'seo_robots';

    /** Stored values that remove the item from the sitemap. */
    public const NOINDEX_VALUES = ['noindex', 'noindex,nofollow'];

    /**
     * Validate a value written through a Core manager. NULL / '' mean inherit.
     *
     * @throws InvalidArgumentException for anything that is not an exact restriction value
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof RobotsRestriction) {
            return $value->value;
        }

        if (is_string($value) && RobotsRestriction::tryFrom($value) !== null) {
            return $value;
        }

        throw new InvalidArgumentException('Invalid robots policy. Use the default, noindex, nofollow or noindex,nofollow.');
    }

    /** The restriction a stored value carries; an unrecognised stored value inherits. */
    public static function restriction(mixed $stored): ?RobotsRestriction
    {
        return is_string($stored) ? RobotsRestriction::tryFrom($stored) : null;
    }

    /** @return array<string, string> option value => untranslated label ('' = inherit) */
    public static function labels(): array
    {
        return [
            'noindex' => 'Noindex (hide from search engines, keep following links)',
            'nofollow' => 'Nofollow (index, but do not follow links)',
            'noindex,nofollow' => 'Noindex and nofollow',
        ];
    }
}
