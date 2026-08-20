<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin\Actions;

use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * Pure state operation: copy the default-locale value into a target locale
 * (Phase 8.3).
 *
 * Powers {@see \TheNguyen\CMS\Filament\Localized\CopyFromDefaultLocaleAction}.
 * Framework-agnostic and side-effect free — it takes a state array and returns a
 * new one, so it is trivially testable and never persists. Respects an overwrite
 * flag: without it, a target that already has a value is left untouched (the UI
 * asks for confirmation only when it would overwrite — see {@see wouldOverwrite()}).
 */
final class CopyFromDefaultLocale
{
    /**
     * @param  array<string, string|null>  $state
     * @return array<string, string|null>
     */
    public function apply(array $state, LocaleOptions $locales, string $targetLocale, bool $overwrite = false): array
    {
        $default = $locales->defaultCode();

        if ($default === null || $default === $targetLocale) {
            return $state;
        }

        $source = $state[$default] ?? null;
        if (! is_string($source)) {
            return $state; // nothing authored in the default locale
        }

        if (! $overwrite && $this->wouldOverwrite($state, $targetLocale)) {
            return $state;
        }

        $state[$targetLocale] = $source;

        return $state;
    }

    /**
     * Whether the target locale already holds a non-empty value (so the UI should
     * confirm before overwriting).
     *
     * @param array<string, string|null> $state
     */
    public function wouldOverwrite(array $state, string $targetLocale): bool
    {
        $existing = $state[$targetLocale] ?? null;

        return is_string($existing) && $existing !== '';
    }
}
