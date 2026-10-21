<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use RuntimeException;

/**
 * Thrown when a profile field/group key is re-registered by a different source,
 * or re-registered by the same source with an incompatible definition
 * (Account Profile Field Engine, P1-A). Registration never silently overwrites.
 */
class ProfileFieldCollisionException extends RuntimeException
{
    public static function crossSource(string $key, string $existing, string $incoming): self
    {
        return new self(sprintf(
            'Profile key "%s" is already owned by "%s"; source "%s" may not override it.',
            $key, $existing, $incoming,
        ));
    }

    public static function incompatible(string $key, string $source): self
    {
        return new self(sprintf(
            'Profile key "%s" is already registered by "%s" with a different definition; '
            .'re-registration must be identical.',
            $key, $source,
        ));
    }
}
