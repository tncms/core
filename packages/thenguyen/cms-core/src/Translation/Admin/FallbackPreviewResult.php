<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

/**
 * The read-only outcome of a fallback preview (Phase 8.3): the value the frontend
 * WOULD show for a locale, which locale it came from, whether that was a fallback
 * (a different locale than requested), and whether anything was found at all.
 *
 * Purely informational — it is never persisted.
 */
final class FallbackPreviewResult
{
    public function __construct(
        public readonly ?string $value,
        public readonly ?string $sourceLocale,
        public readonly bool $isFallback,
        public readonly bool $found,
    ) {
    }
}
