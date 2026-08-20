<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;

/**
 * The single entry point for reading translations. It owns locale resolution,
 * the fallback chain, cache lookups and driver selection so that no module ever
 * reads translations directly.
 *
 * @since 1.0
 *
 * @stable
 */
interface TranslationResolverInterface
{
    /** Resolve a key through a driver + the fallback chain (cache-aware). */
    public function resolve(TranslationKey $key, ?TranslationContext $context = null): TranslationResult;

    /** Resolve an already-loaded multi-locale value through the fallback chain. */
    public function resolveValue(LocalizedValue $value, ?TranslationContext $context = null): TranslationResult;
}
