<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Contracts;

use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;

/**
 * Resolves one field of a localized entity through the Translation Engine
 * (Phase 8.2).
 *
 * It maps the (entity, field) to a translation key, selects the storage-backed
 * driver, and delegates to the engine {@see \TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface}
 * — so entity reads reuse the one resolver, the one fallback chain and the one
 * cache. It never resolves locale or fallback itself.
 */
interface LocalizedEntityResolverInterface
{
    /** Full result (value + locale + stage + cache flag) for a field. */
    public function resolve(LocalizedEntityInterface $entity, string $field, ?TranslationContext $context = null): TranslationResult;

    /** Convenience: the resolved string for a field in $locale (null → current). */
    public function value(LocalizedEntityInterface $entity, string $field, ?string $locale = null): ?string;
}
