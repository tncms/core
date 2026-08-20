<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Persistence;

/**
 * P6.1 — the persistence contract for the Platform Route Dictionary.
 *
 * A store is STORAGE ONLY: it reads and writes the raw dictionary data
 * (`key => [locale => segment]`). It knows nothing of Runtime projection, resolvers, URLs, or
 * the immutable {@see \TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary} — the
 * {@see RouteDictionaryLoader} turns store data into the frozen Runtime dictionary. No Runtime
 * consumer ever reads a store directly.
 */
interface RouteDictionaryStoreInterface
{
    /**
     * The persisted raw dictionary data, or an empty array when nothing is stored.
     *
     * @return array<string, array<string, string>>
     */
    public function load(): array;

    /**
     * Persist raw dictionary data. Storage side only — it performs NO Runtime construction and
     * NO projection; validation and collision rejection happen in the loader / Runtime.
     *
     * @param  array<string, array<string, string>>  $data
     */
    public function save(array $data): void;

    /** Whether a persisted dictionary currently exists. */
    public function exists(): bool;

    /**
     * The distinct locales present in the persisted data.
     *
     * @return list<string>
     */
    public function locales(): array;

    /**
     * The Route Keys present in the persisted data.
     *
     * @return list<string>
     */
    public function keys(): array;
}
