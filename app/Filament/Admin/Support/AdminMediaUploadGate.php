<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

/**
 * Single authorization rule for every admin surface that creates media from a
 * browser upload (the Media → Upload page and the inline MediaPicker upload).
 *
 * The check is `media.upload` only: `admin.access`, page access, `media.view`
 * and the frontend `media.upload_own` never imply it. It is evaluated on every
 * call — grants are resolved per request — so a revocation applies to the next
 * Livewire request even on a page or modal that was opened earlier.
 */
final class AdminMediaUploadGate
{
    public const PERMISSION = 'media.upload';

    public static function allows(): bool
    {
        return cms_can(self::PERMISSION);
    }

    public static function authorize(): void
    {
        abort_unless(self::allows(), 403);
    }
}
