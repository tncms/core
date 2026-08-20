<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Validation;

use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * Validates a {@see LocalizedValue} before it is written to storage (Phase 8.1).
 *
 * All rules are opt-in via config('translation.storage.validation'), so the
 * default behaviour is permissive (partial translations are normal and valid).
 * The rules:
 *
 *   - locale exists          (strict_locales)   — reject a locale the registry
 *                                                 does not know.
 *   - duplicate prevention   (always)           — reject a raw input list that
 *                                                 repeats a locale; a LocalizedValue
 *                                                 map cannot itself hold duplicates,
 *                                                 and the storage unique index is the
 *                                                 final guard.
 *   - empty value handling   (reject_empty)     — reject an empty-string value.
 *   - fallback compatibility (require_resolvable)— reject a value that would always
 *                                                 miss the fallback chain (no locale
 *                                                 and no raw source).
 *
 * An empty locale code ('') is always rejected — it is never a valid address.
 */
final class LocalizedValueValidator
{
    /**
     * @param array{strict_locales?: bool, reject_empty?: bool, require_resolvable?: bool} $config
     */
    public function __construct(
        private readonly LocaleRegistryInterface $registry,
        private readonly array $config = [],
    ) {
    }

    public function validate(TranslationKey $key, LocalizedValue $value): void
    {
        $strict = (bool) ($this->config['strict_locales'] ?? false);
        $rejectEmpty = (bool) ($this->config['reject_empty'] ?? false);
        $requireResolvable = (bool) ($this->config['require_resolvable'] ?? false);

        foreach ($value->toArray() as $locale => $text) {
            $locale = (string) $locale;

            if ($locale === '') {
                throw new LocalizedValidationException(
                    "Empty locale code is not allowed (key '{$key->toString()}').",
                );
            }

            if ($strict && ! $this->registry->has($locale)) {
                throw new LocalizedValidationException(
                    "Unknown locale '{$locale}' for key '{$key->toString()}'.",
                );
            }

            if ($rejectEmpty && $text === '') {
                throw new LocalizedValidationException(
                    "Empty value for locale '{$locale}' is not allowed (key '{$key->toString()}').",
                );
            }
        }

        if ($requireResolvable && ! $this->resolvable($value)) {
            throw new LocalizedValidationException(
                "Value for key '{$key->toString()}' is not resolvable (no locale value and no raw source).",
            );
        }
    }

    /**
     * Reject a raw locale list that names the same locale twice. Use this to
     * validate form/import input before building a {@see LocalizedValue} (which
     * would otherwise silently collapse duplicates, last-write-wins).
     *
     * @param array<int, string> $localeCodes
     */
    public function assertUniqueLocales(array $localeCodes): void
    {
        $seen = [];

        foreach ($localeCodes as $code) {
            $code = (string) $code;

            if (isset($seen[$code])) {
                throw new LocalizedValidationException("Duplicate locale '{$code}'.");
            }

            $seen[$code] = true;
        }
    }

    /**
     * Whether the value can produce anything through the fallback chain: at least
     * one non-empty locale value, or a non-empty raw source.
     */
    public function resolvable(LocalizedValue $value): bool
    {
        if ($value->raw !== null && $value->raw !== '') {
            return true;
        }

        foreach ($value->toArray() as $text) {
            if ($text !== '') {
                return true;
            }
        }

        return false;
    }
}
