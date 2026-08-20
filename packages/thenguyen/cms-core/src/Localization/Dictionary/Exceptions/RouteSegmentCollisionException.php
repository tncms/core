<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Exceptions;

use RuntimeException;

/**
 * Raised when two distinct Route Keys project to the SAME localized segment within one
 * locale (P5H.0A collision contract, Phase G / INV-DICTIONARY-05).
 *
 * Collisions MUST surface during dictionary registration — never during request handling.
 */
final class RouteSegmentCollisionException extends RuntimeException
{
    public static function between(string $keyA, string $keyB, string $locale, string $segment): self
    {
        return new self(sprintf(
            'Localized route segment collision: keys [%s] and [%s] both project to "%s" in locale [%s]. A localized segment must map to exactly one route key per locale.',
            $keyA,
            $keyB,
            $segment,
            $locale,
        ));
    }
}
