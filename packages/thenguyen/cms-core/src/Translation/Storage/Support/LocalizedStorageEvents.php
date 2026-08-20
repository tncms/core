<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Support;

/**
 * Localized-storage lifecycle hook names + a best-effort dispatcher (Phase 8.1).
 *
 * Mirrors {@see \TheNguyen\CMS\Translation\Support\TranslationEvents}: events fire
 * through the core hook system ({@see do_action}) when available and are strictly
 * best-effort — a broken listener never breaks a write. Listeners are for
 * observability/side-effects (e.g. warming a secondary cache) and must not be
 * relied on for correctness.
 *
 * Payload conventions:
 *   creating/created/updating/updated  → (TranslationKey $key, LocalizedValue $value)
 *   deleted                            → (TranslationKey $key)
 *   cache_invalidated                  → (?TranslationKey $key)
 */
final class LocalizedStorageEvents
{
    public const CREATING = 'cms.localized.creating';

    public const CREATED = 'cms.localized.created';

    public const UPDATING = 'cms.localized.updating';

    public const UPDATED = 'cms.localized.updated';

    public const DELETED = 'cms.localized.deleted';

    public const CACHE_INVALIDATED = 'cms.localized.cache_invalidated';

    public static function fire(string $event, mixed ...$payload): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        try {
            do_action($event, ...$payload);
        } catch (\Throwable) {
            // Best effort — a listener must never break a storage write.
        }
    }
}
