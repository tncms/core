<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions;

use RuntimeException;

/**
 * P6.3 — raised when a Route Dictionary source is registered too late: after the
 * Dictionary has already been composed (the registry is read once, at boot, and then locked).
 *
 * Sources are a BUILD-TIME concern. Plugins register them during boot — before the immutable
 * Runtime Dictionary is built. A registration after that point would silently never reach the
 * Runtime, so the registry fails closed and surfaces the ordering error instead.
 */
final class RouteDictionarySourceRegistryException extends RuntimeException
{
    public static function locked(string $sourceId): self
    {
        return new self(sprintf(
            'Route dictionary source [%s] was registered after the Dictionary was composed. Register sources at boot (in a plugin provider), never at request time.',
            $sourceId,
        ));
    }
}
