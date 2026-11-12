<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

/**
 * Single read-authorization rule for admin media discovery: the MediaPicker
 * library grid and selected-item metadata, the Rich Editor "Insert Media"
 * snapshot and the admin media search all require `media.view`.
 *
 * `admin.access`, page access, `media.upload` and the frontend `media.view_own`
 * never imply it. It is evaluated on every call — grants are resolved per
 * request — so a revocation applies to the next Livewire request even on a page
 * or modal that was opened earlier.
 */
final class AdminMediaLibraryGate
{
    public const PERMISSION = 'media.view';

    public static function allows(): bool
    {
        return cms_can(self::PERMISSION);
    }
}
