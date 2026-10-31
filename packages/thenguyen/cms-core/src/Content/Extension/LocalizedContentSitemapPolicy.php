<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Locale-aware owner indexability (CORE-38-TOPLIST-SEAMS-R1), an optional
 * refinement of {@see ContentSitemapPolicy}.
 *
 * When a provider implements it, Core asks with the locale of the sitemap
 * chunk being built (once per owner per chunk, never per item) and with the
 * page locale on render, instead of calling the locale-blind
 * sitemapExcludedIds(). The same rules apply: a throw excludes the ids, an
 * excluded id is absent from that locale's sitemap and noindex on that
 * locale's page.
 */
interface LocalizedContentSitemapPolicy extends ContentSitemapPolicy
{
    /**
     * @param  list<int>  $contentIds
     * @return list<int> the subset that must not be listed in $locale
     */
    public function sitemapExcludedIdsForLocale(array $contentIds, string $locale): array;
}
