<?php

declare(strict_types=1);

namespace App\Search;

/**
 * A selectable option in the "Search in:" dropdown (Phase 3.1.6N-B foundation).
 *
 * A scope maps a user-facing choice to one or more entity types. The synthetic
 * {@see ALL} scope spans every registered type; every registered type also
 * yields its own single-type scope. The `types` list is the seam that lets a
 * later UI phase merge fine-grained types into coarse buckets (e.g. "Articles" =
 * post + page, "Knowledge" = the Knowledge Library types) WITHOUT changing this
 * contract.
 */
final class SearchScope
{
    /** Reserved key for the "everything" scope. */
    public const ALL = 'all';

    /**
     * @param  list<string>  $types  Entity types this scope resolves to.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $types,
    ) {}

    /**
     * The synthetic "All" scope spanning every registered type.
     *
     * @param  list<string>  $types
     */
    public static function all(array $types): self
    {
        return new self(self::ALL, 'All', array_values($types));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'types' => $this->types,
        ];
    }
}
