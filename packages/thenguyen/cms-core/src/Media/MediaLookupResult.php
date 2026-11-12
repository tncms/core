<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

/**
 * Result of a bounded media lookup (CORE-P12-1). Every distinct requested id lands in exactly one
 * bucket. `missing` covers unknown, deleted, private and not-permitted ids alike, so a caller can
 * never tell them apart (no enumeration through the lookup).
 */
final class MediaLookupResult
{
    /**
     * @param  array<int, MediaAsset>  $found  keyed by id, in request order
     * @param  list<int>  $missing
     * @param  list<string>  $invalid  malformed inputs, as strings
     */
    public function __construct(
        public readonly array $found,
        public readonly array $missing,
        public readonly array $invalid,
    ) {}

    public function get(int $id): ?MediaAsset
    {
        return $this->found[$id] ?? null;
    }

    public function has(int $id): bool
    {
        return isset($this->found[$id]);
    }
}
