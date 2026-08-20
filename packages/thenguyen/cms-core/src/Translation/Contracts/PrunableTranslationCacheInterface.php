<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

/**
 * Optional capability for a {@see TranslationCacheInterface} that can invalidate
 * a subset of entries instead of flushing everything (Phase 8.2).
 *
 * This is a separate, additive interface so the base cache contract stays
 * unchanged: callers that want key-level invalidation feature-detect it
 * (`$cache instanceof PrunableTranslationCacheInterface`) and fall back to
 * `flush()` when it is absent. Resolved cache keys embed the translation key as a
 * segment, so forgetting every entry containing that segment invalidates exactly
 * one field across all locale/driver combinations.
 */
interface PrunableTranslationCacheInterface
{
    /**
     * Forget every cached entry whose composite key contains $fragment.
     *
     * @return int number of entries removed
     */
    public function forgetContaining(string $fragment): int;
}
