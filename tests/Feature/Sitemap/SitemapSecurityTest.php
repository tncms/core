<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Sitemap\CoreSitemapProvider;
use TheNguyen\CMS\Sitemap\SitemapProvider;
use TheNguyen\CMS\Sitemap\SitemapUrlValidator;

/**
 * CORE-SITEMAP-R1 Gate J regressions for the independent security review
 * (docs/core-sitemap/CORE-SITEMAP-R1-SECURITY.md): host-dependent caching,
 * empty-index fail-closed, URL normalisation tricks, XML-invalid characters,
 * unbounded invalid streams and cheap rejection of junk part names.
 */
final class SitemapSecurityTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
    }

    private function validator(): SitemapUrlValidator
    {
        app('router')->setRoutes(new RouteCollection);
        Route::middleware(['web', 'cms.plugin-active:p'])->get('shop/{any}', static fn () => 'x')->where('any', '.*');
        Route::middleware(['web', 'cms.plugin-active:p'])->get('{locale}/shop/{any}', static fn () => 'x')->where('any', '.*');
        app('router')->getRoutes()->refreshNameLookups();

        return new SitemapUrlValidator(app('router'), 'http://localhost', ['vi', 'en'], 'vi', false);
    }

    public function test_m1_responses_for_a_foreign_host_are_not_shared_cacheable(): void
    {
        $this->smPost('Alpha');

        $own = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringContainsString('public', (string) $own->headers->get('Cache-Control'));
        $this->assertSame('Host', $own->headers->get('Vary'));

        $foreign = $this->get('http://attacker.example/sitemap.xml')->assertOk();
        $this->assertStringContainsString('no-store', (string) $foreign->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $foreign->headers->get('Cache-Control'));

        $child = $this->get('http://attacker.example/sitemaps/core-posts-vi-1.xml')->assertOk();
        $this->assertStringContainsString('no-store', (string) $child->headers->get('Cache-Control'));
    }

    public function test_m2_a_failing_core_provider_never_publishes_an_empty_index(): void
    {
        app()->instance(CoreSitemapProvider::class, new class implements SitemapProvider
        {
            public function types(): array
            {
                return ['posts'];
            }

            public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int
            {
                throw new \RuntimeException('db down');
            }

            public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable
            {
                return [];
            }
        });

        $response = $this->get('/sitemap.xml')->assertStatus(503);
        $this->assertSame('300', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_m2_a_failing_core_rebuild_serves_the_last_good_index(): void
    {
        $this->smPost('Alpha');
        $good = $this->indexNames();
        $this->smPost('Beta');

        app()->instance(CoreSitemapProvider::class, new class implements SitemapProvider
        {
            public function types(): array
            {
                return ['posts'];
            }

            public function keyAt(string $type, string $locale, ?int $after, int $offset): ?int
            {
                throw new \RuntimeException('db down');
            }

            public function entries(string $type, string $locale, ?int $after, ?int $until, int $limit): iterable
            {
                return [];
            }
        });

        $this->assertSame($good, $this->indexNames());
    }

    /** @return iterable<string, array{string, string}> */
    public static function trickUrls(): iterable
    {
        yield 'dot-dot segment' => ['http://localhost/shop/../blog/x', 'vi'];
        yield 'encoded dot-dot' => ['http://localhost/shop/%2e%2e/blog/x', 'vi'];
        yield 'dot segment' => ['http://localhost/shop/./x', 'vi'];
        yield 'backslash' => ['http://localhost/shop/a\\b', 'vi'];
        yield 'encoded slash' => ['http://localhost/shop/a%2Fb', 'vi'];
        yield 'encoded backslash' => ['http://localhost/shop/a%5cb', 'vi'];
        yield 'uppercase locale prefix' => ['http://localhost/EN/shop/x', 'en'];
        yield 'default locale with its own prefix' => ['http://localhost/vi/shop/x', 'vi'];
        yield 'XML noncharacter' => ["http://localhost/shop/a\u{FFFE}", 'vi'];
        yield 'userinfo' => ['http://user@localhost/shop/x', 'vi'];
        yield 'other port' => ['http://localhost:8080/shop/x', 'vi'];
        yield 'other scheme' => ['https://localhost/shop/x', 'vi'];
        yield 'fragment' => ['http://localhost/shop/x#top', 'vi'];
        yield 'protocol-relative' => ['//localhost/shop/x', 'vi'];
    }

    #[DataProvider('trickUrls')]
    public function test_l1_l2_normalisation_tricks_are_refused(string $url, string $locale): void
    {
        $this->assertFalse($this->validator()->valid($url, $locale, 'p'));
    }

    public function test_l1_the_same_validator_accepts_canonical_plugin_urls(): void
    {
        $validator = $this->validator();

        $this->assertTrue($validator->valid('http://localhost/shop/x', 'vi', 'p'));
        $this->assertTrue($validator->valid('http://localhost/en/shop/x', 'en', 'p'));
        $this->assertTrue($validator->valid('http://localhost/shop/s%C3%A1ch?page=2', 'vi', 'p'));
        $this->assertFalse($validator->valid('http://localhost/shop/x', 'vi', 'q'), 'route owned by another plugin');
    }

    public function test_l3_an_endless_stream_of_invalid_entries_terminates(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $this->smPlugin('sm-loop');
        $this->smItems('sm-loop', ['books' => [1 => 'ok']]);
        $this->smRoutes('sm-loop');
        $this->assertTrue($this->smCode('sm-loop')->registerSitemap());
        $this->assertContains('sm-loop-books-vi-1', $this->indexNames());

        // Every entry is invalid and the stream never ends: reading stops at
        // 4 x limit and the listed part renders empty (never a phantom 404).
        $this->smClass('sm-loop', 'Provider')::$mode = 'endless';
        $this->assertSame([], $this->chunkLocs('sm-loop-books-vi-1'));
    }

    public function test_l5_junk_part_names_are_rejected_before_any_manifest_work(): void
    {
        $this->smPost('Alpha');
        $this->indexNames();

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        foreach (['x', 'core-posts', 'core-posts-vi', 'core-posts-vi-0', 'core-posts-vi-1234567', str_repeat('a', 150)] as $name) {
            $this->get('/sitemaps/'.$name.'.xml')->assertNotFound();
        }

        $this->assertSame(0, $queries);
    }
}
