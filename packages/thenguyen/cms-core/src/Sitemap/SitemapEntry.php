<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use DateTimeInterface;

/** One sitemap `<url>`: the canonical location plus optional lastmod and hreflang alternates. */
final class SitemapEntry
{
    /** @param  array<string, string>  $alternates  locale => absolute URL */
    public function __construct(
        public readonly int $key,
        public readonly string $loc,
        public readonly ?DateTimeInterface $lastmod = null,
        public readonly array $alternates = [],
    ) {}
}
