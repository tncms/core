<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use Filament\Notifications\Notification;

/**
 * CORE-H-Z1-REMEDIATION-R1 H-N7 (WCAG 4.1.2 / 1.1.1): Filament 5.6.6 renders a notification's close
 * button as an icon-only <button> with no accessible name (Notification::toHtml()).
 *
 * Filament builds every notification through the container (Notification::make() resolves
 * static::class, and fromArray() re-dispatches to a bound child class), so Core binds this subclass in
 * AdminPanelProvider::boot(). It adds a translated aria-label to that one button and nothing else:
 * when the vendor markup no longer contains exactly one unnamed close button, the HTML is returned
 * unchanged. Notifications with a custom view are rendered by that view and are not touched.
 */
class AccessibleNotification extends Notification
{
    private const CLOSE_BUTTON = '/<button\b[^>]*\bclass="fi-icon-btn fi-no-notification-close-btn"[^>]*>/';

    public function toHtml(): string
    {
        $html = parent::toHtml();

        if (preg_match_all(self::CLOSE_BUTTON, $html, $matches) !== 1 || str_contains($matches[0][0], 'aria-label')) {
            return $html;
        }

        $named = rtrim(substr($matches[0][0], 0, -1)).' aria-label="'.e(tn_trans('Close')).'">';

        return str_replace($matches[0][0], $named, $html);
    }
}
