<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedRecordInterface;

/**
 * Immutable pairing of a {@see TranslationKey} and its stored {@see LocalizedValue}.
 * Returned by the repository so callers get a stable snapshot of what is stored
 * for a key without touching the backend directly.
 */
final class LocalizedRecord implements LocalizedRecordInterface
{
    public function __construct(
        private readonly TranslationKey $key,
        private readonly LocalizedValue $value,
    ) {
    }

    public function key(): TranslationKey
    {
        return $this->key;
    }

    public function value(): LocalizedValue
    {
        return $this->value;
    }

    public function locales(): array
    {
        return $this->value->locales();
    }

    public function get(string $locale): ?string
    {
        return $this->value->get($locale);
    }

    public function isEmpty(): bool
    {
        return $this->value->isEmpty();
    }
}
