<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/** A persisted ownership row (CORE-CONTENT-EXT-R1). Immutable. */
final class ContentOwnership
{
    public function __construct(
        public readonly int $contentId,
        public readonly string $owner,
        public readonly ContentFallbackPolicy $fallback,
        public readonly ContentAvailability $availability,
        public readonly ?int $retryAfter = null,
        public readonly bool $operatorAssigned = false,
    ) {}
}
