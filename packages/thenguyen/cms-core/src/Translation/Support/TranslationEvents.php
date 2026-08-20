<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Support;

/**
 * Translation lifecycle hook names + a best-effort dispatcher.
 *
 * Events fire through the core hook system ({@see do_action}) when available and
 * are strictly best-effort: a broken listener never breaks resolution. Listeners
 * are for diagnostics/observability only and must not mutate the result.
 */
final class TranslationEvents
{
    public const RESOLVING = 'cms.translation.resolving';

    public const RESOLVED = 'cms.translation.resolved';

    public const CACHE_HIT = 'cms.translation.cache_hit';

    public const CACHE_MISS = 'cms.translation.cache_miss';

    public const INVALIDATED = 'cms.translation.invalidated';

    public static function fire(string $event, mixed ...$payload): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        try {
            do_action($event, ...$payload);
        } catch (\Throwable) {
            // Best effort — observability must never break resolution.
        }
    }
}
