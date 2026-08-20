<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin\Support;

/**
 * Localized-admin lifecycle hook names + a best-effort dispatcher (Phase 8.3).
 *
 * Fired around building components and hydrating/dehydrating admin state, plus a
 * locale-switch signal. Best-effort via {@see do_action}: a broken listener never
 * breaks the form. Observability/extension only — never relied on for correctness.
 */
final class LocalizedAdminEvents
{
    public const BUILDING = 'cms.localized.admin.building';

    public const BUILT = 'cms.localized.admin.built';

    public const HYDRATING = 'cms.localized.admin.hydrating';

    public const HYDRATED = 'cms.localized.admin.hydrated';

    public const DEHYDRATING = 'cms.localized.admin.dehydrating';

    public const DEHYDRATED = 'cms.localized.admin.dehydrated';

    public const LOCALE_SWITCHED = 'cms.localized.admin.locale_switched';

    public static function fire(string $event, mixed ...$payload): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        try {
            do_action($event, ...$payload);
        } catch (\Throwable) {
            // Best effort — a listener must never break the admin form.
        }
    }
}
