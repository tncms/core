<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

use InvalidArgumentException;

/**
 * One dictionary entry — the projection of a {@see RouteKey} to a {@see LocalizedRouteSegment}
 * for a single locale (P5H.0A, Phase C). The immutable unit a future dictionary is built from.
 *
 * The entry is pure data: it carries the identity (key), the target locale, and the localized
 * projection (segment). It owns no resolution, storage, or URL logic.
 */
final class DictionaryEntry
{
    public function __construct(
        public readonly RouteKey $key,
        public readonly string $locale,
        public readonly LocalizedRouteSegment $segment,
    ) {
        if (trim($locale) === '') {
            throw new InvalidArgumentException('A dictionary entry requires a non-empty locale code.');
        }
    }

    /** The (locale, segment) signature used for collision detection. */
    public function signature(): string
    {
        return $this->locale."\0".$this->segment->value;
    }
}
