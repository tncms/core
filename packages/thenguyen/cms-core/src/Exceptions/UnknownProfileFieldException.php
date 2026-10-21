<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by the value engine when a write targets a key that is not a
 * registered, active profile field. Writes fail closed — a value is never
 * created for an unknown or forged key (Account Profile Field Engine, P1-B).
 */
class UnknownProfileFieldException extends InvalidArgumentException
{
    public static function for(string $key): self
    {
        return new self(sprintf('Unknown profile field "%s".', $key));
    }
}
