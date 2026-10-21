<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a profile field/group key collides with a reserved name — an auth
 * identifier, a users-table column, or the core.* namespace claimed by a
 * non-core source (Account Profile Field Engine, P1-A).
 */
class ReservedProfileFieldKeyException extends InvalidArgumentException
{
    public static function for(string $key, string $reason): self
    {
        return new self(sprintf('Profile field key "%s" is reserved: %s', $key, $reason));
    }
}
