<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Exceptions;

use RuntimeException;

/**
 * CORE-L10N.1B — thrown when a localized-resource resolver with a duplicate key is registered.
 * Resolver collisions are fatal (fail fast at boot/test) rather than a silent override.
 */
final class LocalizedResourceResolverException extends RuntimeException
{
    public static function duplicateKey(string $key): self
    {
        return new self("A localized resource resolver with key [{$key}] is already registered.");
    }
}
