<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Read-only owner availability check for {@see ContentSeoProvider}
 * implementations (CORE-38-TOPLIST-SEAMS-R1).
 *
 * Covers owner state that became invalid outside the owner's own write
 * workflow (a corrupt approved snapshot, a missing owner record), which the
 * persisted availability cannot know about until the owner reconciles it.
 *
 * Core calls it once per public single-content request, only for content the
 * provider's plugin owns, only while that plugin is active, and only after
 * Core's publication gate and the persisted availability already allow a 200 —
 * never for previews, listings, search or the sitemap. Return null (no
 * opinion) or Available to keep Core's decision; any other case restricts the
 * response with the same status mapping as the persisted state. A probe can
 * never make content available that Core or the persisted state refused.
 *
 * MUST be read-only: Core runs it inside a database transaction that is
 * always rolled back, and never persists its answer. A throw, or a value PHP's
 * return type rejects, is a 404 (fail closed). An owner whose probe can
 * answer unavailable must also exclude those ids from
 * {@see ContentSitemapPolicy::sitemapExcludedIds()}, which Core calls in batch.
 */
interface ContentAvailabilityProbe
{
    public function availabilityFor(ContentSeoRequest $request): ?ContentAvailability;
}
