<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException;

/**
 * A Route Key — the permanent, locale-independent Runtime identity of a static route
 * segment (P5H.0A, Phase A / INV-DICTIONARY-01).
 *
 * Examples: "products", "product-category", "brand". A Route Key is NEVER localized;
 * localized segments ("san-pham", "produkte") are projections of it ({@see LocalizedRouteSegment}),
 * never the identity. Immutable value object; equality is by value.
 */
final class RouteKey
{
    /** Lowercase ASCII identity: a letter, then alnum groups joined by single hyphens. */
    private const PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    private function __construct(public readonly string $value) {}

    public static function of(string $value): self
    {
        $candidate = trim($value);

        if ($candidate === '' || preg_match(self::PATTERN, $candidate) !== 1) {
            throw RouteKeyException::invalid($value);
        }

        return new self($candidate);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
