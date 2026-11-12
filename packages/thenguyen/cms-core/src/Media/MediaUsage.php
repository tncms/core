<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Models\Media;

/**
 * Whether a media asset is still referenced (CORE-P12-1). Used by owner self-deletion, which is
 * refused while an asset is in use. Checks Core's own reference sites, then asks plugins through
 * the `cms.media.in_use` filter (bool $inUse, int $mediaId, string $url): a plugin that stores
 * media ids or URLs in its own tables returns true when it still uses the asset.
 *
 * Core reference sites (all Core-migrated tables): cms_contents.featured_image and
 * cms_terms.featured_image (URL or id, including trashed rows, which can be restored),
 * cms_mediables, cms_settings values equal to the URL, and cms_widgets settings containing the
 * URL. One bounded EXISTS query each, short-circuiting on the first hit. featured_image is not
 * indexed, so the content check is a linear scan: acceptable for a rare, user-initiated delete.
 */
final class MediaUsage
{
    public function inUse(Media $media): bool
    {
        $id = (int) $media->getKey();
        $url = (string) $media->url;
        $refs = array_values(array_filter([$url, (string) $id], static fn (string $v): bool => $v !== '' && $v !== '0'));

        $core = DB::table('cms_contents')->whereIn('featured_image', $refs)->exists()
            || DB::table('cms_terms')->whereIn('featured_image', $refs)->exists()
            || DB::table('cms_mediables')->where('media_id', $id)->exists()
            || ($url !== '' && DB::table('cms_settings')->where('value', $url)->exists())
            || ($url !== '' && $this->widgetsMention($url));

        if ($core) {
            return true;
        }

        return (bool) apply_filters('cms.media.in_use', false, $id, $url);
    }

    /** Widget settings are JSON: match the URL as stored raw or with JSON-escaped slashes. */
    private function widgetsMention(string $url): bool
    {
        return DB::table('cms_widgets')->where(static function ($q) use ($url): void {
            foreach (array_unique([$url, str_replace('/', '\/', $url)]) as $needle) {
                $q->orWhereRaw('settings LIKE ? ESCAPE ?', ['%'.self::escapeLike($needle).'%', '!']);
            }
        })->exists();
    }

    private static function escapeLike(string $value): string
    {
        // Explicit ESCAPE '!' behaves the same on SQLite and MySQL.
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
