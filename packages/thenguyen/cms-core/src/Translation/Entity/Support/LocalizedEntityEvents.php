<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Support;

/**
 * Entity-level localized lifecycle hook names + a best-effort dispatcher
 * (Phase 8.2). Mirrors the storage-level events one layer down, but fires at the
 * granularity of a whole entity write/delete.
 *
 * Best-effort via {@see do_action}: a broken listener never breaks a write.
 *
 * Payload conventions:
 *   creating/created/updating/updated → (LocalizedEntityInterface $entity, array $fields)
 *   deleting/deleted                  → (LocalizedEntityInterface $entity, array $fields)
 *   cache_invalidated                 → (LocalizedEntityInterface $entity)
 */
final class LocalizedEntityEvents
{
    public const CREATING = 'cms.localized.entity.creating';

    public const CREATED = 'cms.localized.entity.created';

    public const UPDATING = 'cms.localized.entity.updating';

    public const UPDATED = 'cms.localized.entity.updated';

    public const DELETING = 'cms.localized.entity.deleting';

    public const DELETED = 'cms.localized.entity.deleted';

    public const CACHE_INVALIDATED = 'cms.localized.entity.cache_invalidated';

    public static function fire(string $event, mixed ...$payload): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        try {
            do_action($event, ...$payload);
        } catch (\Throwable) {
            // Best effort — a listener must never break an entity write.
        }
    }
}
