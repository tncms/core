<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;

/**
 * A source of translations for a {@see TranslationKey}. This is a seam only —
 * Phase 8.0 ships just {@see \TheNguyen\CMS\Translation\Drivers\NullTranslationDriver}.
 * Future drivers (Database, JSON, YAML, Remote API, AI) implement this contract
 * and are registered on the driver registry.
 *
 * @since 1.0
 *
 * @stable
 */
interface TranslationDriverInterface
{
    /** Unique driver name used for selection/config. */
    public function name(): string;

    /** Fetch one locale's value, or null when the driver has none. */
    public function get(TranslationKey $key, string $locale, TranslationContext $context): ?string;

    public function has(TranslationKey $key, string $locale, TranslationContext $context): bool;

    /** Fetch every locale the driver holds for the key as a single value object. */
    public function all(TranslationKey $key, TranslationContext $context): LocalizedValue;
}
