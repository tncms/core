<?php

declare(strict_types=1);

namespace Tests\Feature\ContentAuthority;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\ContentAuthority\Concerns\ContentAuthorityFixtures;
use Tests\TestCase;
use TheNguyen\CMS\ContentAuthority\ContentWriteContext;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1 — public cache invalidation after the
 * outermost commit (T-C3). Proves the stale-refill race, not just flush counts:
 * a reader that resolves from the OLD committed data while the write is still
 * uncommitted must not leave that old data valid after the commit.
 *
 * Stores: array and file (non-transactional), database on a SEPARATE
 * connection (a real cache table that does not take part in the content
 * transaction, as with a dedicated cache database).
 */
final class PublicCacheCommitTest extends TestCase
{
    use ContentAuthorityFixtures;
    use RefreshDatabase;

    private ?string $cacheDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->actingAs($this->superAdmin());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/cache/ca-test-'.getmypid()));

        if ($this->cacheDb !== null) {
            DB::disconnect('ca_cache');
            File::delete($this->cacheDb);
        }

        parent::tearDown();
    }

    private function useStore(string $store): void
    {
        if ($store === 'file') {
            config(['cache.stores.ca_file' => ['driver' => 'file', 'path' => storage_path('framework/cache/ca-test-'.getmypid())]]);
            config(['cache.default' => 'ca_file']);
        } elseif ($store === 'database') {
            $this->cacheDb = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tncms-ca-cache-'.getmypid().'.sqlite';
            File::put($this->cacheDb, '');
            config(['database.connections.ca_cache' => ['driver' => 'sqlite', 'database' => $this->cacheDb, 'prefix' => '', 'foreign_key_constraints' => false]]);
            Schema::connection('ca_cache')->create('cache', function ($t): void {
                $t->string('key')->primary();
                $t->mediumText('value');
                $t->integer('expiration');
            });
            config(['cache.stores.ca_db' => ['driver' => 'database', 'connection' => 'ca_cache', 'table' => 'cache', 'lock_connection' => 'ca_cache']]);
            config(['cache.default' => 'ca_db']);
        } else {
            config(['cache.default' => 'array']);
        }

        Cache::forgetDriver(config('cache.default'));
        Cache::flush();
        app()->forgetInstance('cms.public_cache');
    }

    private function cache(): PublicContentCacheManager
    {
        return app('cms.public_cache');
    }

    /** A reader that resolves a path and caches whatever the source returns. */
    /** Returns which source generation the cached resolution came from: 1 = old, 2 = fresh. */
    private function read(int $generation): int
    {
        $value = $this->cache()->rememberResolution('en', '/race', static fn (): array => ['reference_type' => 'content', 'reference_id' => $generation]);
        $this->assertNotSame('BYPASS', $this->cache()->lastCacheState(), 'the public cache must be enabled for this proof');

        return (int) $value['reference_id'];
    }

    /** @return list<array{0: string}> */
    public static function stores(): array
    {
        return [['array'], ['file'], ['database']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_a_concurrent_refill_before_commit_cannot_outlive_the_commit(string $store): void
    {
        $this->useStore($store);
        $post = $this->makeContent(['title' => 'Cached']);
        $this->read(1);

        DB::transaction(function () use ($post): void {
            $this->contents()->update($post, ['locale' => 'en', 'title' => 'New title'], $post->revision, ContentWriteContext::interactive());

            // Still uncommitted: another request misses, re-resolves from the OLD
            // committed data and caches it. (Simulated: drop the entry, refill.)
            Cache::forget($this->cache()->resolveKey('en', '/race'));
            $this->assertSame(1, $this->read(1));
        });

        // After commit the version moved past the refill: the next reader misses
        // and sees fresh data instead of the stale refill.
        $this->assertSame(2, $this->read(2));
        $this->assertSame('MISS', $this->cache()->lastCacheState());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function test_rollback_never_flushes_and_several_writes_coalesce_into_one_flush(string $store): void
    {
        $this->useStore($store);
        $post = $this->makeContent(['title' => 'Cached']);
        $version = $this->cache()->version();

        try {
            DB::transaction(function () use ($post): void {
                $this->contents()->update($post, ['locale' => 'en', 'title' => 'Rolled back'], $post->revision, ContentWriteContext::interactive());
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame($version, $this->cache()->version(), 'a rolled-back write must not flush');

        $post->refresh();
        $insideVersion = null;
        DB::transaction(function () use ($post, &$insideVersion): void {
            $post = $this->contents()->update($post, ['locale' => 'en', 'title' => 'One'], $post->revision, ContentWriteContext::interactive());
            $this->contents()->update($post, ['locale' => 'vi', 'title' => 'Hai'], $post->revision, ContentWriteContext::interactive());
            $insideVersion = $this->cache()->version();
        });

        $this->assertSame($version, $insideVersion, 'nothing flushed before commit');
        $this->assertSame($version + 1, $this->cache()->version(), 'one coalesced flush after commit');
    }

    public function test_a_flush_registered_in_a_rolled_back_savepoint_does_not_suppress_a_later_one(): void
    {
        $this->useStore('array');
        $post = $this->makeContent(['title' => 'Cached']);
        $version = $this->cache()->version();

        DB::transaction(function () use ($post): void {
            try {
                DB::transaction(function () use ($post): void {
                    $this->contents()->update($post, ['locale' => 'en', 'title' => 'Inner'], $post->revision, ContentWriteContext::interactive());
                    throw new RuntimeException('inner abort');
                });
            } catch (RuntimeException) {
            }

            $post->refresh();
            $this->contents()->update($post, ['locale' => 'en', 'title' => 'Outer'], $post->revision, ContentWriteContext::interactive());
        });

        $this->assertSame($version + 1, $this->cache()->version());
    }
}
