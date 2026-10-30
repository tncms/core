<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/**
 * Owner indexability for {@see ContentSeoProvider} implementations
 * (CORE-SITEMAP-R1, unified in CORE-37).
 *
 * The single owner answer to "may this content be indexed?". Core calls it
 * once per sitemap chunk with the ids of the owner's content in that chunk —
 * never per item — and once per page render for that page's id, only while
 * the owner is active. An excluded id is absent from the sitemap and noindex
 * on the page. A throw excludes the ids (fail closed). Owned content of an
 * owner that registers a ContentSeoProvider WITHOUT this interface is kept
 * out of the sitemap, since its robots cannot be known in batch. Overrides
 * returning noindex must exclude the same ids here.
 */
interface ContentSitemapPolicy
{
    /**
     * @param  list<int>  $contentIds
     * @return list<int> the subset that must not be listed
     */
    public function sitemapExcludedIds(array $contentIds): array;
}
