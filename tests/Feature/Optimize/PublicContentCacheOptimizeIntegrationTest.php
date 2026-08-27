<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Facades\PublicContentCache;
use TheNguyen\CMS\Services\CmsCachePolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * CORE-OPTIMIZE-1 — end-to-end integration with a real Core optimization-cache
 * consumer (§18, §28): the public content resolution cache. Proves ON caches,
 * OFF bypasses without writing, and re-enable never resurrects a stale value.
 */
class PublicContentCacheOptimizeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the policy gate from the TTL gate: keep TTL positive so the
        // only variable under test is the CMS Cache toggle.
        config(['cms.cache.public_ttl' => 3600]);
    }

    private function cache(): PublicContentCacheManager
    {
        return app('cms.public_cache');
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    /** @return array{reference_type: string, reference_id: int} */
    private function ref(int $id): array
    {
        return ['reference_type' => 'content', 'reference_id' => $id];
    }

    public function test_enabled_populates_then_serves_from_cache(): void
    {
        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return $this->ref(11);
        };

        $first = $this->cache()->rememberResolution('en', 'about', $resolver);
        $this->assertSame($this->ref(11), $first);
        $this->assertSame('MISS', $this->cache()->lastCacheState());

        $second = $this->cache()->rememberResolution('en', 'about', $resolver);
        $this->assertSame($this->ref(11), $second);
        $this->assertSame('HIT', $this->cache()->lastCacheState());

        $this->assertSame(1, $calls, 'Resolver must run once while caching is enabled.');
    }

    public function test_disabled_bypasses_and_writes_nothing(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $calls = 0;
        $resolver = function () use (&$calls) {
            $calls++;

            return $this->ref(22);
        };

        $a = $this->cache()->rememberResolution('en', 'contact', $resolver);
        $b = $this->cache()->rememberResolution('en', 'contact', $resolver);

        $this->assertSame($this->ref(22), $a);
        $this->assertSame($this->ref(22), $b);
        $this->assertSame('BYPASS', $this->cache()->lastCacheState());
        $this->assertSame(2, $calls, 'Every read must hit source of truth while caching is OFF.');
    }

    public function test_reenable_does_not_resurrect_a_stale_value(): void
    {
        // 1. ON: cache the OLD reference.
        $old = $this->cache()->rememberResolution('en', 'team', fn () => $this->ref(1));
        $this->assertSame($this->ref(1), $old);

        // 2. OFF then 3. ON — each toggle persists a Setting, whose model event
        //    advances the public-cache version epoch, orphaning the old entry.
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        // 4. The source of truth now resolves to a NEW reference.
        $calls = 0;
        $new = $this->cache()->rememberResolution('en', 'team', function () use (&$calls) {
            $calls++;

            return $this->ref(2);
        });

        $this->assertSame($this->ref(2), $new, 'Re-enable must serve fresh data, not the pre-disable value.');
        $this->assertSame('MISS', $this->cache()->lastCacheState());
        $this->assertSame(1, $calls);
    }

    public function test_toggle_off_advances_epoch_via_setting_write(): void
    {
        $before = PublicContentCache::version();

        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $this->assertGreaterThan($before, PublicContentCache::version());
    }
}
