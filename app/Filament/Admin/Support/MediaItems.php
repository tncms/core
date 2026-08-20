<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Media;

/**
 * Single source of truth for the image list shown by both the Rich Editor
 * "Insert Media" modal and the Featured Image media modal. Keeping the query +
 * normalisation here guarantees both modals show identical data.
 */
class MediaItems
{
    /**
     * Image media (mime image/*), newest first, normalised for the modals.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function imageItems(int $limit = 50): array
    {
        if (! Schema::hasTable('cms_media')) {
            return [];
        }

        return self::imageQuery()
            ->limit($limit)
            ->get(self::columns())
            ->map(static fn (Media $m): array => self::normalize($m))
            ->all();
    }

    /**
     * Base query for image media, newest first. Table-guarded so callers must
     * confirm the table exists (imageItems/searchImages do this for you).
     */
    public static function imageQuery(): Builder
    {
        return Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->orderByDesc('created_at');
    }

    /**
     * Normalise a Media model into the array shape both modals consume.
     *
     * @return array{
     *     id:int, url:string, name:string, filename:string,
     *     alt:?string, title:?string, caption:?string, description:?string,
     *     width:?int, height:?int, size:?int, created_at:?string,
     *     seo_alt:string, seo_title:?string
     * }
     */
    public static function normalize(Media $m): array
    {
        return [
            'id' => (int) $m->id,
            'url' => (string) $m->url,
            'name' => (string) ($m->original_filename ?? $m->filename ?? ''),
            'filename' => (string) ($m->filename ?? ''),
            'alt' => self::nullableString($m->alt),
            'title' => self::nullableString($m->title),
            'caption' => self::nullableString($m->caption),
            'description' => self::nullableString($m->description),
            'width' => $m->width !== null ? (int) $m->width : null,
            'height' => $m->height !== null ? (int) $m->height : null,
            'size' => $m->size !== null ? (int) $m->size : null,
            'created_at' => $m->created_at?->toIso8601String(),
            'seo_alt' => $m->seoAlt(),
            'seo_title' => $m->seoTitle(),
        ];
    }

    /**
     * Find a single normalised image item by its stored URL, or null when the
     * URL is empty or no `cms_media` row matches it (e.g. an external URL).
     *
     * @return array<string, mixed>|null
     */
    public static function findByUrl(string $url): ?array
    {
        if ($url === '' || ! Schema::hasTable('cms_media')) {
            return null;
        }

        $media = Media::query()->where('url', $url)->first(self::columns());

        return $media instanceof Media ? self::normalize($media) : null;
    }

    /**
     * Server-side image search over filename/alt/title/caption/description,
     * newest first. Mirrors the client-side search used in the modals so any
     * caller wanting a filtered list (without loading all 50) gets identical
     * matching semantics.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function searchImages(string $search, int $limit = 50): array
    {
        if (! Schema::hasTable('cms_media')) {
            return [];
        }

        $term = trim($search);
        $columns = self::columns();
        $query = self::imageQuery();

        if ($term !== '') {
            $like = '%' . $term . '%';

            // Search across every column a media item can be found by — including
            // url/path (so an imported file is findable by its stored location)
            // and the metadata columns. Each is guarded against the live schema
            // so a fresh install (pre-caption/description migration) never errors.
            $query->where(function (Builder $q) use ($like): void {
                foreach (self::searchableColumns() as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }

        return $query
            ->limit(max(1, $limit))
            ->get($columns)
            ->map(static fn (Media $m): array => self::normalize($m))
            ->all();
    }

    /**
     * Columns the server-side search matches against, intersected with the live
     * schema so it never references a column the current install lacks. Covers
     * the file identity (original_filename/filename), its stored location
     * (url/path) and the editorial metadata.
     *
     * @return array<int, string>
     */
    private static function searchableColumns(): array
    {
        $candidates = ['original_filename', 'filename', 'url', 'path', 'alt', 'title', 'caption', 'description'];

        return array_values(array_filter(
            $candidates,
            static fn (string $column): bool => Schema::hasColumn('cms_media', $column),
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Column list to select. caption/description are added only when the
     * columns exist (a fresh install before the 0.9.3 migration). Not memoised:
     * Schema::hasColumn hits the cached schema builder, so this stays cheap
     * while remaining correct across migrations and long-lived processes.
     *
     * @return array<int, string>
     */
    private static function columns(): array
    {
        $columns = ['id', 'url', 'original_filename', 'filename', 'alt', 'title', 'width', 'height', 'size', 'created_at'];

        if (Schema::hasColumn('cms_media', 'caption')) {
            $columns[] = 'caption';
        }

        if (Schema::hasColumn('cms_media', 'description')) {
            $columns[] = 'description';
        }

        return $columns;
    }
}
