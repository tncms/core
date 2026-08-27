<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Services\Cache\PublicContentCacheWarmer;
use TheNguyen\CMS\Services\CmsCachePolicy;

/**
 * CORE-OPTIMIZE-2 §16–§24 — scoped, bounded, single-flight public content warm.
 */
class CmsCacheRebuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.cache.public_ttl' => 3600]);
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    private function policy(): CmsCachePolicy
    {
        return app('cms.cache_policy');
    }

    private function warmer(): PublicContentCacheWarmer
    {
        return app('cms.cache_warmer');
    }

    private function makeSlug(string $locale, string $path, string $type = 'content', int $id = 1): Slug
    {
        return Slug::create([
            'reference_type' => $type,
            'reference_id' => $id,
            'locale' => $locale,
            'slug' => $path,
            'prefix' => null,
            'full_path' => $path,
            'is_primary' => true,
        ]);
    }

    public function test_rebuild_supported_only_when_enabled_and_corpus_present(): void
    {
        $this->makeSlug('en', 'about');
        $this->assertTrue($this->warmer()->supported());

        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        $this->assertFalse($this->warmer()->supported(), 'Not supported while CMS Cache is OFF.');
    }

    public function test_warm_populates_public_cache_for_each_locale(): void
    {
        $this->makeSlug('en', 'about', 'content', 11);
        $this->makeSlug('vi', 'gioi-thieu', 'content', 11);

        $result = $this->policy()->rebuild();

        $this->assertTrue($result->success);
        $this->assertSame('rebuild', $result->operation);
        $this->assertSame('public_content', $result->domain);
        $this->assertSame(2, $result->processedCount);
        $this->assertSame(0, $result->failedCount);
        $this->assertContains('en', $result->context['locales']);
        $this->assertContains('vi', $result->context['locales']);

        // The warmed keys are the exact keys a live guest request would read:
        // resolving the same path is now a HIT with no resolver run.
        $calls = 0;
        $ref = app('cms.public_cache')->rememberResolution('en', 'about', function () use (&$calls) {
            $calls++;

            return ['reference_type' => 'content', 'reference_id' => 999];
        });
        $this->assertSame(0, $calls, 'Warmed path must resolve from cache, not re-run the resolver.');
        $this->assertSame(['reference_type' => 'content', 'reference_id' => 11], $ref);
        $this->assertSame('HIT', app('cms.public_cache')->lastCacheState());
    }

    public function test_warm_is_idempotent_and_mutates_no_content_or_epoch(): void
    {
        $this->makeSlug('en', 'about', 'content', 11);
        $epochBefore = app('cms.public_cache')->version();
        $slugCountBefore = Slug::query()->count();

        $first = $this->policy()->rebuild();
        $second = $this->policy()->rebuild();

        $this->assertTrue($first->success);
        $this->assertTrue($second->success);
        $this->assertSame(1, $second->processedCount);
        // No content rows created, epoch NOT advanced by warming (warm != invalidate).
        $this->assertSame($slugCountBefore, Slug::query()->count());
        $this->assertSame($epochBefore, app('cms.public_cache')->version());
    }

    public function test_warm_skips_honestly_when_disabled(): void
    {
        $this->makeSlug('en', 'about');
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $result = $this->policy()->rebuild();

        $this->assertFalse($result->success);
        $this->assertSame('optimize.rebuild.skipped_disabled', $result->messageKey);
        $this->assertSame(0, $result->processedCount);
    }

    public function test_warm_reports_already_running_when_locked(): void
    {
        $this->makeSlug('en', 'about');

        // Simulate a concurrent warm holding the single-flight lock.
        $lock = Cache::lock('tncms.public.cache.warm', 120);
        $this->assertTrue($lock->get());

        try {
            $result = $this->policy()->rebuild();
            $this->assertFalseOrAlreadyRunning($result->messageKey);
            $this->assertSame(0, $result->processedCount);
        } finally {
            $lock->release();
        }
    }

    public function test_warm_is_bounded_by_max_urls(): void
    {
        config(['cms.optimize.rebuild_max_urls' => 3]);
        for ($i = 1; $i <= 6; $i++) {
            $this->makeSlug('en', "page-$i", 'content', $i);
        }

        $result = $this->policy()->rebuild();

        $this->assertSame(3, $result->processedCount, 'Warm must stop at the configured cap.');
        $this->assertTrue($result->context['capped']);
        $this->assertSame('optimize.rebuild.partial', $result->messageKey);
    }

    public function test_empty_corpus_warms_zero_honestly_not_a_fake_success(): void
    {
        // Table exists (migrated) but empty: the capability exists, there is just
        // nothing to warm. Honest zero, never a fabricated "content warmed".
        $this->assertTrue($this->policy()->rebuildSupported());

        $result = $this->policy()->rebuild();
        $this->assertTrue($result->success);
        $this->assertSame(0, $result->processedCount);
        $this->assertSame('optimize.rebuild.done', $result->messageKey);
    }

    public function test_rebuild_unsupported_when_cache_disabled(): void
    {
        $this->makeSlug('en', 'about');
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $this->assertFalse($this->policy()->rebuildSupported());
    }

    private function assertFalseOrAlreadyRunning(string $messageKey): void
    {
        $this->assertSame('optimize.rebuild.already_running', $messageKey);
    }
}
