<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Base for providers backed by an Eloquent query: implements the keyset
 * contract from one builder per (type, locale) and maps each bounded batch of
 * rows to entries in one call, so eager loading stays per batch.
 */
abstract class KeysetSitemapProvider implements SitemapProvider
{
    /** The public, indexable rows of $type in $locale, or null when the pair has none. */
    abstract protected function query(string $type, string $locale): ?Builder;

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows  ascending by key
     * @return iterable<SitemapEntry>
     */
    abstract protected function entriesFor(Collection $rows, string $type, string $locale): iterable;

    public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int
    {
        $query = $this->query($type, $locale);

        if ($query === null) {
            return null;
        }

        $key = $query->getModel()->getQualifiedKeyName();
        $value = $query->toBase()
            ->when($after !== null, static fn ($q) => $q->where($key, '>', $after))
            ->reorder($key)
            ->offset(max(0, $offset))
            ->limit(1)
            ->value($key);

        return $value === null ? null : (int) $value;
    }

    public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable
    {
        $query = $this->query($type, $locale);

        if ($query === null) {
            return [];
        }

        $key = $query->getModel()->getQualifiedKeyName();
        $rows = $query
            ->when($after !== null, static fn ($q) => $q->where($key, '>', $after))
            ->when($until !== null, static fn ($q) => $q->where($key, '<=', $until))
            ->reorder($key)
            ->limit(max(1, $limit))
            ->get();

        return $rows->isEmpty() ? [] : $this->entriesFor($rows, $type, $locale);
    }
}
