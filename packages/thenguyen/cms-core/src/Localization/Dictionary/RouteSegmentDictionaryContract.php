<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

/**
 * The Platform-owned projection contract that turns a {@see RouteKey} + locale into a
 * {@see LocalizedRouteSegment} (P5H.0A, Phase E / INV-DICTIONARY-03).
 *
 * ONLY Platform Runtime implements this contract; a plugin never resolves route keys into
 * localized segments and never returns a localized segment of its own. A null result means
 * "no localized segment for this locale" — the caller (Platform URL projection) falls back to
 * the route key's canonical segment. The concrete dictionary (data + storage + wiring into
 * {@see \TheNguyen\CMS\Localization\LocalizedUrlGenerator}) is implemented in P5H.1; freezing
 * this interface is what makes that a pure implementation phase.
 */
interface RouteSegmentDictionaryContract
{
    /**
     * The localized segment for $key in $locale, or null to fall back to the key's canonical
     * segment. Implementations are read-only projections and MUST NOT mutate identity.
     */
    public function segmentFor(RouteKey $key, string $locale): ?LocalizedRouteSegment;
}
