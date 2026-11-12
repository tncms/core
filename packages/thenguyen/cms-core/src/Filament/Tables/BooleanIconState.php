<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Tables;

/**
 * Text alternative for a boolean IconColumn cell (H-Z1-M3, WCAG 1.1.1 / 1.3.1).
 *
 * Filament draws the state as an aria-hidden icon with no text, so the cell wrapper is
 * exposed as an image named "Yes" / "No"; the column header already gives the context.
 * Filament renders extraAttributes values unescaped, hence e().
 */
final class BooleanIconState
{
    /** @return array{role: string, 'aria-label': string} */
    public static function attributes(mixed $state): array
    {
        return ['role' => 'img', 'aria-label' => e($state ? tn_trans('Yes') : tn_trans('No'))];
    }
}
