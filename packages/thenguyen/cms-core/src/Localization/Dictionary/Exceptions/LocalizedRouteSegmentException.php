<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Exceptions;

use InvalidArgumentException;

/**
 * Raised for an invalid localized route segment (P5H.0A validation contract, Phase H).
 *
 * A localized segment is a URL slug projection of a Route Key: lowercase (no uppercase
 * Latin), hyphen-separated, with no spaces or underscores. Non-Latin scripts (e.g. CJK)
 * are permitted since they carry no letter case.
 */
final class LocalizedRouteSegmentException extends InvalidArgumentException
{
    public static function invalid(string $value): self
    {
        return new self(sprintf(
            'Invalid localized route segment [%s]: use a lowercase, hyphen-separated slug — no spaces, underscores, or uppercase (e.g. "san-pham").',
            $value,
        ));
    }
}
