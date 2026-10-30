<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheNguyen\CMS\Services\SeoManager;
use TheNguyen\CMS\Sitemap\SitemapUnavailableException;

/**
 * Serves the SEO infrastructure endpoints: robots.txt (SeoManager) and the
 * sitemap index plus its chunked children (SitemapManager, CORE-SITEMAP-R1).
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
        $sitemap = app('cms.sitemap');

        if (! $sitemap->enabled()) {
            return $this->missing();
        }

        try {
            return response($sitemap->indexXml(), 200, $this->xmlHeaders());
        } catch (SitemapUnavailableException) {
            return $this->unavailable();
        }
    }

    public function sitemapChunk(string $name): Response
    {
        $sitemap = app('cms.sitemap');

        // Every part name ends in -{locale}-{n}; anything else is rejected
        // before any manifest is loaded.
        if (preg_match('/-[a-z]{2,3}(?:[-_][a-z0-9]{1,8})?-[1-9][0-9]{0,5}$/', $name) !== 1 || ! $sitemap->enabled()) {
            return $this->missing();
        }

        try {
            $part = $sitemap->part($name);
            $stream = $part === null ? null : $sitemap->chunkStream($part);
        } catch (SitemapUnavailableException) {
            return $this->unavailable();
        }

        if ($stream === null) {
            return $this->missing();
        }

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $this->xmlHeaders());
    }

    /**
     * URLs are built from the request root (as canonical tags are), so a
     * response is shared-cacheable only on the configured APP_URL host; any
     * other Host gets a private, uncached answer that a proxy cannot reuse.
     *
     * @return array<string, string>
     */
    private function xmlHeaders(): array
    {
        $configured = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $shared = $configured !== '' && strtolower(request()->getHost()) === $configured;

        return [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => $shared ? 'public, max-age='.max(0, (int) config('cms.sitemap.max_age', 300)) : 'private, no-store',
            'Vary' => 'Host',
            'X-Robots-Tag' => 'noindex',
        ];
    }

    private function missing(): Response
    {
        return response('Not Found', 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function unavailable(): Response
    {
        return response('Service Unavailable', 503, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Retry-After' => '300',
        ]);
    }
}
