<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

/**
 * One admin locale choice (Phase 8.3): its code, human label, endonym, and
 * whether it is the site default / fallback. Immutable and framework-agnostic —
 * the Filament components render from these, they don't invent locales.
 */
final class LocaleOption
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $native = null,
        public readonly bool $isDefault = false,
        public readonly bool $isFallback = false,
    ) {
    }
}
