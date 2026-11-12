<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Forms;

use TheNguyen\CMS\Taxonomy\Directory\InvalidTermDirectoryRequest;
use TheNguyen\CMS\Taxonomy\Directory\TaxonomyDirectory;
use TheNguyen\CMS\Taxonomy\Directory\TermSearchCriteria;
use TheNguyen\CMS\Taxonomy\Directory\TermSummary;

/**
 * CORE-P16-1 — Filament option maps (term id → plain-text label) backed by the Core taxonomy
 * directory. Used by {@see TermSelect} and by selects that only sometimes pick a term (menu items).
 *
 * Every map is bounded: a first page / search page is at most TaxonomyDirectory::DEFAULT_LIMIT
 * rows, and labels resolve at most TaxonomyDirectory::MAX_LOOKUP_IDS selected ids. `labels()` is
 * also Filament's server-side validation source: an id it omits (unknown, deleted, wrong taxonomy,
 * malformed, or — in strict mode — untranslated in the locale) is rejected on save.
 *
 * Labels are plain text; Filament escapes them (never enable allowHtml() on these selects).
 */
final class TermPickerOptions
{
    /** @return array<int, string> */
    public static function firstPage(string $taxonomy, string $locale, bool $strict): array
    {
        return self::search($taxonomy, $locale, '', $strict);
    }

    /** @return array<int, string> */
    public static function search(string $taxonomy, string $locale, string $query, bool $strict): array
    {
        try {
            $page = self::directory()->search(new TermSearchCriteria($taxonomy, $locale, $query, $strict));
        } catch (InvalidTermDirectoryRequest $e) {
            report($e);

            return [];
        }

        return self::map($page->items);
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array<int, string>
     */
    public static function labels(iterable $ids, string $taxonomy, string $locale, bool $strict): array
    {
        try {
            $result = self::directory()->lookup($ids, $locale, $taxonomy);
        } catch (InvalidTermDirectoryRequest $e) {
            // Oversize or invalid request: no labels, so Filament rejects the submitted state.
            report($e);

            return [];
        }

        $found = $strict
            ? array_filter($result->found, static fn (TermSummary $s): bool => $s->hasTranslation)
            : $result->found;

        return self::map($found);
    }

    /**
     * @param  iterable<TermSummary>  $items
     * @return array<int, string>
     */
    private static function map(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[$item->id] = $item->label;
        }

        return $out;
    }

    private static function directory(): TaxonomyDirectory
    {
        return app('cms.taxonomy.directory');
    }
}
