<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * One addressable localized record: a {@see TranslationKey} paired with its full
 * {@see LocalizedValue} (all locales). This is the unit the repository hands back
 * from a read — a stable, read-only view over what is stored for a key.
 */
interface LocalizedRecordInterface
{
    public function key(): TranslationKey;

    public function value(): LocalizedValue;

    /** @return array<int, string> locale codes that carry a non-empty value */
    public function locales(): array;

    /** One locale's stored string (no fallback), or null when absent/empty. */
    public function get(string $locale): ?string;

    public function isEmpty(): bool;
}
