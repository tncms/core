<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Owner-scoped SEO metadata for managed content (CORE-CONTENT-EXT-R1).
 *
 * Core calls a provider only for content its plugin owns, only while that
 * plugin is active, only for a public (non-preview) request, and only after
 * Core's publication gate and the availability decision allow a 200. The
 * provider MUST return published/approved values — never draft metadata.
 * Returning null keeps Core's metadata. A throw or invalid value is ignored.
 */
interface ContentSeoProvider
{
    public function seoFor(ContentSeoRequest $request): ?ContentSeoOverride;
}
