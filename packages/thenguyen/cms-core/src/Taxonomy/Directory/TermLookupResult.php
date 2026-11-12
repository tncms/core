<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

/**
 * Result of an exact-id batch lookup. Every requested input lands in exactly one bucket:
 * `found` (keyed by id, in request order), `missing` (well-formed id that is unknown, soft-deleted,
 * of another or unsupported taxonomy — never substituted) or `invalid` (not a positive integer id).
 */
final class TermLookupResult
{
    /**
     * @param  array<int, TermSummary>  $found
     * @param  list<int>  $missing
     * @param  list<string>  $invalid
     */
    public function __construct(
        public readonly array $found,
        public readonly array $missing,
        public readonly array $invalid,
    ) {}

    public function get(int $id): ?TermSummary
    {
        return $this->found[$id] ?? null;
    }

    /** @return list<int> */
    public function foundIds(): array
    {
        return array_keys($this->found);
    }
}
