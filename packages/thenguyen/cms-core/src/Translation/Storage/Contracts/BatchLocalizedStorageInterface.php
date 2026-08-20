<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * Optional capability for a {@see LocalizedStorageInterface} backend that can read
 * many keys in one round trip (Phase 8.2).
 *
 * Separate and additive so the base storage contract is unchanged: the entity
 * layer feature-detects it to load a whole entity's fields (or many entities) in
 * a single query, and falls back to per-key {@see LocalizedStorageInterface::get()}
 * when a backend does not implement it.
 */
interface BatchLocalizedStorageInterface
{
    /**
     * Read several keys at once.
     *
     * @param  array<int, TranslationKey>  $keys
     * @return array<string, LocalizedValue>  keyed by TranslationKey::toString(); a
     *                                        key with nothing stored maps to an
     *                                        empty value (never absent from the map)
     */
    public function getMany(array $keys): array;
}
