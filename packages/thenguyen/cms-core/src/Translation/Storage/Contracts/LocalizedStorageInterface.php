<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * A persistence backend for localized values (Phase 8.1).
 *
 * A storage driver reads and writes the full locale map for one
 * {@see TranslationKey}. It is deliberately narrow — no fallback, no cache, no
 * validation — so every backend (Database, and future JSON/YAML/Remote/AI) is
 * interchangeable behind this contract. The engine consumes a storage backend
 * through {@see \TheNguyen\CMS\Translation\Storage\Drivers\StorageTranslationDriver}.
 *
 * Read methods MUST be defensive: a missing table or backend error resolves to
 * "no value" (empty {@see LocalizedValue} / null), never an exception, so a read
 * can never break a page. Write methods surface real failures to the caller.
 */
interface LocalizedStorageInterface
{
    /** Unique backend name (used when registering the engine driver). */
    public function name(): string;

    /** Every stored locale for a key as one value object (empty when none). */
    public function get(TranslationKey $key): LocalizedValue;

    /** One locale's stored string, or null when absent. */
    public function getLocale(TranslationKey $key, string $locale): ?string;

    /**
     * Replace the full locale map for a key: locales present in $value are
     * upserted, locales absent from $value are removed. Atomic.
     */
    public function put(TranslationKey $key, LocalizedValue $value): void;

    /**
     * Write a single locale, leaving the others untouched. A null value removes
     * that locale (equivalent to {@see forgetLocale()}).
     */
    public function putLocale(TranslationKey $key, string $locale, ?string $value): void;

    /** Remove every locale stored for a key. */
    public function forget(TranslationKey $key): void;

    /** Remove one locale for a key. */
    public function forgetLocale(TranslationKey $key, string $locale): void;

    /** Whether the key (optionally a specific locale) has any stored value. */
    public function exists(TranslationKey $key, ?string $locale = null): bool;
}
