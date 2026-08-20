<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

use TheNguyen\CMS\Localization\Dictionary\Exceptions\LocalizedRouteSegmentException;

/**
 * A localized route segment — the per-locale URL slug projection of a {@see RouteKey}
 * (P5H.0A, Phase C / INV-DICTIONARY-02). A segment is a projection, never an identity.
 *
 * Validation (Phase H): a hyphen-separated slug of unicode letters/numbers with NO
 * whitespace, NO underscores, and NO uppercase Latin. Non-Latin scripts are allowed
 * (e.g. "商品") since they carry no letter case. Leading/trailing/consecutive hyphens
 * are rejected. Normalization is trim only — invalid input is rejected, never coerced.
 *
 * Allowed:  "san-pham", "thuong-hieu", "danh-muc", "商品", "produkte"
 * Rejected: "san pham" (space), "san_pham" (underscore), "Products" / "SanPham" (uppercase)
 */
final class LocalizedRouteSegment
{
    /** Unicode letters/numbers in hyphen-joined groups (no leading/trailing/double hyphen). */
    private const PATTERN = '/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u';

    private function __construct(public readonly string $value) {}

    public static function of(string $value): self
    {
        $candidate = trim($value);

        if ($candidate === ''
            || preg_match('/[A-Z]/', $candidate) === 1               // no uppercase Latin
            || preg_match(self::PATTERN, $candidate) !== 1) {         // no spaces / underscores / bad hyphens
            throw LocalizedRouteSegmentException::invalid($value);
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
