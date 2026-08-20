<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions;

use RuntimeException;

/**
 * P6.2 — raised when two sources of EQUAL priority set the same (key, locale) to different
 * localized segments. Composition fails closed at build time — it never silently overwrites, and
 * never surfaces at request time.
 *
 * (A higher-priority source overriding a lower one is an INTENTIONAL override, not a conflict.)
 */
final class RouteDictionaryCompositionConflictException extends RuntimeException
{
    public static function segment(string $key, string $locale, string $sourceA, string $sourceB, string $segmentA, string $segmentB): self
    {
        return new self(sprintf(
            'Route dictionary composition conflict: sources [%s] and [%s] (equal priority) set [%s][%s] to "%s" vs "%s". Give one source a higher priority to override, or reconcile the data.',
            $sourceA,
            $sourceB,
            $key,
            $locale,
            $segmentA,
            $segmentB,
        ));
    }
}
