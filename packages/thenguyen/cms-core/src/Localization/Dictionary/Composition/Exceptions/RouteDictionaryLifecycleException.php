<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions;

use RuntimeException;

/**
 * CORE-PLATFORM-SEAMS-R2 — the Route Dictionary was read before Core finalized
 * it (during plugin registration or while the application is still booting).
 *
 * Such a read would compose and lock the dictionary early, silently refusing
 * every plugin source registered afterwards. It fails closed instead: generate
 * routes in the plugin's routes/web.php (runs after finalization) or defer with
 * app('cms.localized_routes')->defer(...).
 */
final class RouteDictionaryLifecycleException extends RuntimeException
{
    public static function earlyRead(string $phase): self
    {
        return new self(sprintf(
            'The route dictionary was read during %s, before Core finalized it. Read it from routes/web.php or defer with app(\'cms.localized_routes\')->defer().',
            $phase,
        ));
    }
}
