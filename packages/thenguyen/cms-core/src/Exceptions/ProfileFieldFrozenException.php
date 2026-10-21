<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use RuntimeException;

/**
 * Thrown when an admin edit would mutate a locked (Core/plugin-owned) field, or
 * change a data-shape property (type, encryption, options, key) of a field that
 * already has stored values. Such changes are refused server-side even if the
 * Filament UI is bypassed (Account Profile Field Builder, P2).
 */
class ProfileFieldFrozenException extends RuntimeException
{
    public static function locked(string $key): self
    {
        return new self(sprintf('Field "%s" is Core/plugin-owned and cannot be modified here.', $key));
    }

    public static function hasValues(string $key, string $property): self
    {
        return new self(sprintf(
            'Field "%s" already has stored values; its %s cannot be changed without a separate migration.',
            $key, $property,
        ));
    }
}
