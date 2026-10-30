<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-R1 Gates D and J: one Core-owned provider registry with
 * call-site plugin identity, lifecycle-aware consultation, strict URL
 * validation (origin, locale, route ownership, escaping) and per-provider
 * failure isolation.
 */
final class SitemapPluginProviderTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const BOOKS = ['books' => [1 => 'one', 2 => 'two', 3 => 'three']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->smPlugin('sm-lib');
        $this->smPlugin('sm-other');
        $this->smItems('sm-lib', self::BOOKS);
        $this->smItems('sm-other', ['songs' => [7 => 'seven']]);
        $this->smRoutes('sm-lib', 'sm-other');
    }

    public function test_a_plugin_registers_its_own_provider_once(): void
    {
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->assertFalse($this->smCode('sm-lib')->registerSitemap(), 'second registration refused');

        $provider = $this->smClass('sm-lib', 'Provider');
        $this->assertFalse(app('cms.sitemap_providers')->register(new $provider), 'non-plugin caller refused');
        $this->assertFalse($this->smCode('sm-other')->registerSitemap(new $provider), 'cannot register another plugin\'s provider class');

        $this->assertSame(['sm-lib'], array_keys(app('cms.sitemap_providers')->active()));
    }

    public function test_plugin_sets_are_listed_per_locale_in_deterministic_order(): void
    {
        // Registration order is reversed on purpose.
        $this->assertTrue($this->smCode('sm-other')->registerSitemap());
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $names = $this->indexNames();
        $this->assertSame(
            ['sm-lib-books-vi-1', 'sm-lib-books-en-1', 'sm-other-songs-vi-1', 'sm-other-songs-en-1'],
            array_values(array_filter($names, static fn ($n) => ! str_starts_with($n, 'core-'))),
        );
        $this->assertSame('core-home-vi-1', $names[0], 'Core first');

        $this->assertSame(
            ['http://localhost/lib-sm-lib/books/one', 'http://localhost/lib-sm-lib/books/two', 'http://localhost/lib-sm-lib/books/three'],
            $this->chunkLocs('sm-lib-books-vi-1'),
        );
        $xml = $this->get('/sitemaps/sm-lib-books-en-1.xml')->streamedContent();
        $this->assertStringContainsString('<loc>http://localhost/en/lib-sm-lib/books/one</loc>', $xml);
        $this->assertStringContainsString('hreflang="vi" href="http://localhost/lib-sm-lib/books/one"', $xml);
        $this->assertStringContainsString('<lastmod>2026-01-02T03:04:05+00:00</lastmod>', $xml);
    }

    public function test_plugin_sets_are_chunked_with_the_same_algorithm(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $books = [];
        for ($i = 1; $i <= 1100; $i++) {
            $books[$i * 10] = 'b'.$i;
        }
        $this->smItems('sm-lib', ['books' => $books]);
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $first = $this->chunkLocs('sm-lib-books-vi-1');
        $second = $this->chunkLocs('sm-lib-books-vi-2');
        $third = $this->chunkLocs('sm-lib-books-vi-3');
        $this->assertSame([500, 500, 100], [count($first), count($second), count($third)]);
        $this->assertSame(['http://localhost/lib-sm-lib/books/b1', 'http://localhost/lib-sm-lib/books/b500'], [$first[0], $first[499]]);
        $this->assertSame(['http://localhost/lib-sm-lib/books/b501', 'http://localhost/lib-sm-lib/books/b1100'], [$second[0], $third[99]]);
        $this->get('/sitemaps/sm-lib-books-vi-4.xml')->assertNotFound();
    }

    public function test_deactivation_removes_the_plugin_immediately_and_reactivation_restores_it(): void
    {
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->assertContains('sm-lib-books-vi-1', $this->indexNames());

        // Persisted deactivation (as another process would): the provider is still in memory.
        settings()->set('extensions.active_plugins', ['sm-other'], 'array');

        $this->get('/sitemaps/sm-lib-books-vi-1.xml')->assertNotFound();
        $this->assertNotContains('sm-lib-books-vi-1', $this->indexNames(), 'no stale index reference');

        settings()->set('extensions.active_plugins', ['sm-lib', 'sm-other'], 'array');
        $this->assertContains('sm-lib-books-vi-1', $this->indexNames());
    }

    public function test_a_missing_plugin_leaves_no_index_reference(): void
    {
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->assertContains('sm-lib-books-vi-1', $this->indexNames());

        \Illuminate\Support\Facades\File::deleteDirectory($this->smRoot().DIRECTORY_SEPARATOR.'sm-lib');
        app('cms.extension')->flushRegistry();

        $this->assertNotContains('sm-lib-books-vi-1', $this->indexNames());
        $this->get('/sitemaps/sm-lib-books-vi-1.xml')->assertNotFound();
    }

    /** @return iterable<string, array{string}> */
    public static function hostileSamples(): iterable
    {
        yield 'external host' => ['external'];
        yield 'another plugin\'s URL' => ['foreign'];
        yield 'a Core content URL' => ['core'];
        yield 'invalid UTF-8' => ['utf8'];
    }

    #[DataProvider('hostileSamples')]
    public function test_invalid_plugin_urls_are_dropped_and_leave_no_phantom_part(string $mode): void
    {
        $extra = match ($mode) {
            'foreign' => ['url' => 'http://localhost/lib-sm-other/songs/seven'],
            'core' => ['url' => url(content_url($this->smPost('Core Page', english: false), 'vi'))],
            default => [],
        };
        $this->smItems('sm-lib', self::BOOKS, $mode === 'core' ? 'foreign' : $mode);
        $this->smClass('sm-lib', 'Provider')::$extra = $extra;
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $names = $this->indexNames();
        $this->assertEmpty(array_filter($names, static fn ($n) => str_starts_with($n, 'sm-lib-')), 'set omitted');
        $this->get('/sitemaps/sm-lib-books-vi-1.xml')->assertNotFound();
        $this->assertContains('core-home-vi-1', $names);
    }

    public function test_a_locale_mismatched_url_is_refused_for_that_locale_only(): void
    {
        $this->smItems('sm-lib', self::BOOKS, 'wrong-locale');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $names = $this->indexNames();
        $this->assertContains('sm-lib-books-vi-1', $names);
        $this->assertNotContains('sm-lib-books-en-1', $names, 'an unprefixed URL is not an en URL');
    }

    public function test_xml_special_characters_are_escaped_and_bad_lastmod_and_alternates_dropped(): void
    {
        $this->smItems('sm-lib', self::BOOKS, 'xml');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $xml = $this->get('/sitemaps/sm-lib-books-vi-1.xml')->streamedContent();
        $this->assertStringContainsString('<loc>http://localhost/lib-sm-lib/books/one?a=1&amp;b=&lt;2&gt;</loc>', $xml);
        $this->assertSame('http://localhost/lib-sm-lib/books/one?a=1&b=<2>', $this->xmlLocs($xml, 'urlset')[0]);

        $this->smItems('sm-lib', self::BOOKS, 'bad-lastmod');
        $this->assertStringNotContainsString('<lastmod>2999', $this->get('/sitemaps/sm-lib-books-vi-1.xml')->streamedContent());

        $this->smItems('sm-lib', self::BOOKS, 'bad-alternate');
        $xml = $this->get('/sitemaps/sm-lib-books-vi-1.xml')->streamedContent();
        $this->assertStringNotContainsString('evil.example', $xml);
        $this->assertStringNotContainsString('xhtml:link', $xml, 'a single surviving alternate is not emitted');

        $this->smItems('sm-lib', self::BOOKS, 'dupes');
        $locs = $this->chunkLocs('sm-lib-books-vi-1');
        $this->assertSame($locs, array_values(array_unique($locs)));
    }

    public function test_failing_provider_calls_omit_only_that_providers_sets_and_log_no_message(): void
    {
        $this->smItems('sm-lib', self::BOOKS, 'throw-keys');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->assertTrue($this->smCode('sm-other')->registerSitemap());

        Log::spy();
        $names = $this->indexNames();

        $this->assertEmpty(array_filter($names, static fn ($n) => str_starts_with($n, 'sm-lib-')));
        $this->assertContains('sm-other-songs-vi-1', $names);
        $this->assertContains('core-home-vi-1', $names);
        Log::shouldHaveReceived('warning')->withArgs(
            static fn ($message, $context) => $message === 'TN CMS sitemap set failed' && ! str_contains((string) json_encode($context), 'SM-SECRET'),
        );
    }

    public function test_non_ascending_keys_are_refused(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $this->smItems('sm-lib', ['books' => array_combine(range(1, 600), array_map(static fn ($i) => 'b'.$i, range(1, 600)))], 'descending');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $this->assertEmpty(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'sm-lib-')));
    }

    public function test_a_failing_child_answers_503_for_that_child_only(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $this->smItems('sm-lib', ['books' => array_combine(range(1, 600), array_map(static fn ($i) => 'b'.$i, range(1, 600)))], 'throw-entries');
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());

        $this->assertContains('sm-lib-books-vi-2', $this->indexNames());
        $this->get('/sitemaps/sm-lib-books-vi-1.xml')->assertOk();
        $failed = $this->get('/sitemaps/sm-lib-books-vi-2.xml')->assertStatus(503);
        $this->assertSame('300', $failed->headers->get('Retry-After'));
        $this->assertStringNotContainsString('SM-SECRET', (string) $failed->getContent());
        $this->get('/sitemaps/core-home-vi-1.xml')->assertOk();
    }

    public function test_a_plugin_can_only_invalidate_its_own_manifest(): void
    {
        $this->assertTrue($this->smCode('sm-lib')->registerSitemap());
        $this->indexNames();
        $store = Cache::store();
        $core = $store->get('cms.sitemap.version.core');
        $lib = $store->get('cms.sitemap.version.sm-lib');

        $this->smCode('sm-lib')->invalidate();

        $this->assertNotSame($lib, $store->get('cms.sitemap.version.sm-lib'));
        $this->assertSame($core, $store->get('cms.sitemap.version.core'));
    }

    public function test_chunk_size_is_clamped_to_protocol_limits(): void
    {
        config(['cms.sitemap.chunk_size' => 0]);
        $this->assertSame(500, app('cms.sitemap')->chunkSize(), 'floor');
        config(['cms.sitemap.chunk_size' => 10_000_000]);
        $this->assertSame(50000, app('cms.sitemap')->chunkSize(), 'protocol maximum');
        config(['cms.sitemap.chunk_size' => 5000]);
        $this->assertSame(5000, app('cms.sitemap')->chunkSize());
    }
}
