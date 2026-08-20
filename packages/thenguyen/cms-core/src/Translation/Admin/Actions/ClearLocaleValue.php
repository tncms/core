<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin\Actions;

/**
 * Pure state operation: clear one locale's value (Phase 8.3).
 *
 * Powers {@see \TheNguyen\CMS\Filament\Localized\ClearLocaleValueAction}. Sets the
 * locale to null — a deliberate clear that the hydrator omits on save (removing
 * that locale's stored value), as distinct from an authored empty string.
 */
final class ClearLocaleValue
{
    /**
     * @param  array<string, string|null>  $state
     * @return array<string, string|null>
     */
    public function apply(array $state, string $locale): array
    {
        $state[$locale] = null;

        return $state;
    }
}
