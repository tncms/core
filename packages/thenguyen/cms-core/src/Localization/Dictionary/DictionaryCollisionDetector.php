<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteSegmentCollisionException;

/**
 * Detects localized-segment collisions across dictionary entries (P5H.0A, Phase G /
 * INV-DICTIONARY-05).
 *
 * Within a single locale, a localized segment must project to exactly ONE route key. Two
 * DIFFERENT keys mapping to the same (locale, segment) — e.g. "products→vi→san-pham" and
 * "courses→vi→san-pham" — is a collision and MUST be rejected at REGISTRATION time, never at
 * request time. The same key repeating an identical entry is idempotent (not a collision).
 *
 * Pure and stateless: it holds no dictionary data. A future dictionary passes its full entry
 * set through {@see assertNoCollisions()} at build/registration time.
 */
final class DictionaryCollisionDetector
{
    /**
     * @param  iterable<int, DictionaryEntry>  $entries
     *
     * @throws RouteSegmentCollisionException
     */
    public function assertNoCollisions(iterable $entries): void
    {
        /** @var array<string, string> $seen signature => owning key value */
        $seen = [];

        foreach ($entries as $entry) {
            $signature = $entry->signature();
            $owner = $seen[$signature] ?? null;

            if ($owner !== null && $owner !== $entry->key->value) {
                throw RouteSegmentCollisionException::between(
                    $owner,
                    $entry->key->value,
                    $entry->locale,
                    $entry->segment->value,
                );
            }

            $seen[$signature] = $entry->key->value;
        }
    }
}
