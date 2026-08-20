<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Exceptions;

use InvalidArgumentException;

/**
 * Raised for an invalid, reserved, or duplicate Route Key (P5H.0A contract).
 *
 * Route Keys are permanent Runtime identities (INV-DICTIONARY-01). This exception
 * fails a registration EAGERLY (never at request time — INV-DICTIONARY-05).
 */
final class RouteKeyException extends InvalidArgumentException
{
    public static function invalid(string $value): self
    {
        return new self(sprintf(
            'Invalid route key [%s]: a route key is a lowercase ASCII identity (a-z, 0-9, single hyphens), e.g. "product-category".',
            $value,
        ));
    }

    public static function reserved(string $value): self
    {
        return new self(sprintf(
            'Route key [%s] is a reserved Platform identity and may not be registered or redefined by a plugin.',
            $value,
        ));
    }

    public static function duplicate(string $value): self
    {
        return new self(sprintf(
            'Route key [%s] is already registered. Route keys are unique Runtime identities.',
            $value,
        ));
    }
}
