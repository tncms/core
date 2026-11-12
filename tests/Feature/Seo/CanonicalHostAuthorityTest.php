<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Http\Middleware\ApplyCanonicalHost;

/**
 * CORE-SEO-RELEASE-HARDENING-R1, Track H1: the canonical host authority.
 * On Core's public pages and SEO endpoints, absolute URLs (canonical, og:url,
 * hreflang, links, sitemap locs, robots.txt Sitemap:) use the request's own
 * root only when the request host is the configured APP_URL host. Any other
 * host, including a forwarded host that a trusted proxy passes on, gets the
 * configured origin. Forwarded headers count only from trusted proxies
 * (Laravel's trustedproxy.proxies). CMS_CANONICAL_HOST=request keeps the
 * legacy request-root behaviour.
 */
final class CanonicalHostAuthorityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const SITE = 'https://www.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: true);
        config(['app.url' => self::SITE]);
    }

    private function fresh(): void
    {
        app('cms.localization.current_resource')->clear();
        app('cms.seo')->reset();
    }

    /** @return array{status: int, canonical: list<string>, alternates: array<string, string>, html: string, cache: string} */
    private function seoHead(string $url, array $headers = [], array $server = []): array
    {
        $this->fresh();
        $response = $this->withHeaders($headers)->withServerVariables($server)->get($url);
        $html = (string) $response->getContent();
        preg_match_all('/<link rel="canonical" href="([^"]*)"/', $html, $c);
        preg_match_all('/<link rel="alternate" hreflang="([^"]*)" href="([^"]*)"/', $html, $a);

        return ['status' => $response->getStatusCode(), 'canonical' => $c[1],
            'alternates' => array_combine($a[1], $a[2]) ?: [], 'html' => $html,
            'cache' => (string) $response->headers->get('Cache-Control')];
    }

    private function postPath(string $title = 'Bai viet'): string
    {
        $post = $this->smPost($title, english: true);

        return (string) parse_url(content_url($post, 'vi'), PHP_URL_PATH);
    }

    public function test_the_configured_host_keeps_its_own_root_and_scheme(): void
    {
        $path = $this->postPath();

        $this->assertSame([self::SITE.$path], $this->seoHead(self::SITE.$path)['canonical']);
        $this->assertSame(['http://www.example.test'.$path], $this->seoHead('http://www.example.test'.$path)['canonical'], 'scheme follows the request on the configured host');
    }

    public function test_a_foreign_host_never_reaches_canonical_hreflang_links_or_the_sitemap(): void
    {
        $path = $this->postPath();

        $head = $this->seoHead('http://evil.example'.$path);
        $this->assertSame(200, $head['status']);
        $this->assertSame([self::SITE.$path], $head['canonical'], 'exactly one canonical, on the configured origin');
        $this->assertSame(['vi', 'en', 'x-default'], array_keys($head['alternates']));
        foreach ($head['alternates'] as $href) {
            $this->assertStringStartsWith(self::SITE.'/', $href);
        }
        $this->assertStringNotContainsString('evil.example', $head['html']);
        $this->assertStringContainsString('private', $head['cache']);

        $this->fresh();
        app('cms.sitemap')->invalidateCore();
        $index = $this->get('http://evil.example/sitemap.xml');
        $this->assertStringContainsString('no-store', (string) $index->headers->get('Cache-Control'), 'a foreign host is still never shared-cacheable');
        foreach ($this->xmlLocs((string) $index->getContent(), 'sitemapindex') as $loc) {
            $this->assertStringStartsWith(self::SITE.'/', $loc);
            $this->fresh();
            $child = $this->get('http://evil.example'.parse_url($loc, PHP_URL_PATH))->streamedContent();
            $this->assertStringNotContainsString('evil.example', $child);
            foreach ($this->xmlLocs($child, 'urlset') as $url) {
                $this->assertStringStartsWith(self::SITE, $url);
            }
        }

        $this->fresh();
        $robots = (string) $this->get('http://evil.example/robots.txt')->getContent();
        $this->assertStringContainsString('Sitemap: '.self::SITE.'/sitemap.xml', $robots);
        $this->assertStringNotContainsString('evil.example', $robots);
    }

    public function test_locale_prefixed_pages_and_the_home_use_the_configured_origin(): void
    {
        $post = $this->smPost('Song ngu', english: true);
        $en = (string) parse_url(content_url($post, 'en'), PHP_URL_PATH);

        $this->assertSame([self::SITE.$en], $this->seoHead('http://evil.example'.$en)['canonical']);

        $home = $this->seoHead('http://evil.example/en');
        $this->assertSame([self::SITE.'/en'], $home['canonical']);
        $this->assertSame(['vi' => self::SITE, 'en' => self::SITE.'/en', 'x-default' => self::SITE], $home['alternates']);
    }

    public function test_a_spoofed_forwarded_host_is_ignored_unless_the_proxy_is_trusted(): void
    {
        $path = $this->postPath();
        $spoof = ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '80'];

        // No trusted proxies (the default): the headers are ignored.
        $head = $this->seoHead(self::SITE.$path, $spoof);
        $this->assertSame([self::SITE.$path], $head['canonical']);
        $this->assertStringNotContainsString('evil.example', $head['html']);

        // A proxy list that does not include the client: still ignored.
        config(['trustedproxy.proxies' => '10.20.30.40']);
        $head = $this->seoHead(self::SITE.$path, $spoof, ['REMOTE_ADDR' => '127.0.0.1']);
        $this->assertSame([self::SITE.$path], $head['canonical']);
        $this->assertStringNotContainsString('evil.example', $head['html']);
    }

    public function test_a_trusted_proxy_passes_the_public_host_and_scheme_but_never_a_foreign_host(): void
    {
        $path = $this->postPath();
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $proxy = ['REMOTE_ADDR' => '127.0.0.1'];

        // TLS terminated at the proxy; PHP sees an internal host and plain http.
        $head = $this->seoHead('http://10.0.0.5:8080'.$path,
            ['X-Forwarded-Host' => 'www.example.test', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'], $proxy);
        $this->assertSame([self::SITE.$path], $head['canonical']);
        $this->assertStringNotContainsString('10.0.0.5', $head['html']);

        // Even a trusted proxy cannot make a foreign host canonical.
        $head = $this->seoHead('http://10.0.0.5:8080'.$path,
            ['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'http', 'X-Forwarded-Port' => '80'], $proxy);
        $this->assertSame([self::SITE.$path], $head['canonical']);
        $this->assertStringNotContainsString('evil.example', $head['html']);
    }

    public function test_a_custom_port_is_part_of_the_configured_origin(): void
    {
        config(['app.url' => 'http://site.test:8080']);
        $path = $this->postPath();

        $this->assertSame(['http://site.test:8080'.$path], $this->seoHead('http://evil.example'.$path)['canonical']);
        $this->assertSame(['http://site.test:8080'.$path], $this->seoHead('http://site.test:8080'.$path)['canonical']);
    }

    public function test_a_later_configured_host_request_is_not_affected_by_an_earlier_foreign_one(): void
    {
        $path = $this->postPath();

        $this->assertSame([self::SITE.$path], $this->seoHead('http://evil.example'.$path)['canonical']);
        $this->assertSame(['http://www.example.test'.$path], $this->seoHead('http://www.example.test'.$path)['canonical'], 'the forced origin never leaks into the next request');
    }

    public function test_the_request_policy_keeps_the_legacy_request_root(): void
    {
        config(['cms.deployment.canonical_host' => 'request']);
        $path = $this->postPath();

        $this->assertSame(['http://alias.example'.$path], $this->seoHead('http://alias.example'.$path)['canonical']);
    }

    public function test_an_unknown_policy_value_fails_safe_to_the_configured_origin(): void
    {
        config(['cms.deployment.canonical_host' => 'REQUEST; drop']);
        $path = $this->postPath();

        $this->assertSame([self::SITE.$path], $this->seoHead('http://evil.example'.$path)['canonical']);
    }

    public function test_without_a_usable_configured_origin_the_request_root_is_kept(): void
    {
        $path = $this->postPath();

        foreach (['', 'not a url', 'ftp://www.example.test', '//www.example.test'] as $appUrl) {
            config(['app.url' => $appUrl]);
            $this->assertSame(['http://alias.example'.$path], $this->seoHead('http://alias.example'.$path)['canonical'], var_export($appUrl, true));
        }
    }

    public function test_only_core_public_and_seo_routes_carry_the_canonical_host_middleware(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['cms.home', 'cms.robots', 'cms.sitemap', 'cms.sitemap.chunk'] as $name) {
            $this->assertNotNull($routes->getByName($name), $name);
            $this->assertContains(ApplyCanonicalHost::class, app('router')->gatherRouteMiddleware($routes->getByName($name)), $name);
        }

        foreach ($routes->getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'admin') || str_starts_with($uri, 'livewire') || str_starts_with($uri, 'install') || str_starts_with($uri, 'login') || str_starts_with($uri, 'account')) {
                $this->assertNotContains(ApplyCanonicalHost::class, app('router')->gatherRouteMiddleware($route), $uri);
            }
        }
    }
}
