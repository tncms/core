<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\SeoManager;

/**
 * Serves the SEO infrastructure endpoints: robots.txt and sitemap.xml.
 * Thin — all content is built by the SeoManager.
 */
class SeoController
{
    public function __construct(private readonly SeoManager $seo)
    {
    }

    public function robots(): Response
    {
        return response($this->seo->robotsTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function sitemap(): Response
    {
        return response($this->seo->sitemapXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
