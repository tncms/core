<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a profile field/group definition is malformed: a non-namespaced
 * key, an unknown field type, an unknown group, an invalid visibility/
 * editability, or metadata carrying arbitrary PHP (closures/objects)
 * (Account Profile Field Engine, P1-A).
 */
class InvalidProfileFieldDefinitionException extends InvalidArgumentException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
