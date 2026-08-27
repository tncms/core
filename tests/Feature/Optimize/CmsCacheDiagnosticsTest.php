<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use TheNguyen\CMS\Services\Cache\CmsCacheDiagnostics;
use TheNguyen\CMS\Services\Cache\CmsCacheOperationResult;
use TheNguyen\CMS\Services\CmsCachePolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * CORE-OPTIMIZE-2 §9/§12/§13/§37 — read-only diagnostics aggregator.
 */
class CmsCacheDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.cache.public_ttl' => 3600]);
    }

    private function diagnostics(): CmsCacheDiagnostics
    {
        return app('cms.cache_diagnostics');
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    public function test_snapshot_reports_effective_state_when_enabled(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        $snap = $this->diagnostics()->snapshot();

        $this->assertTrue($snap['policy']['enabled']);
        $this->assertSame('optimize.cache_enabled', $snap['policy']['setting_key']);
        $this->assertSame(3600, $snap['public_cache']['configured_ttl']);
        $this->assertTrue($snap['public_cache']['effective_enabled']);
        $this->assertSame('cache', $snap['public_cache']['read_mode']);
        $this->assertSame('healthy', $snap['status']);
        $this->assertIsInt($snap['public_cache']['epoch']);
    }

    public function test_snapshot_reports_disabled_status_when_policy_off(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $snap = $this->diagnostics()->snapshot();

        $this->assertFalse($snap['policy']['enabled']);
        $this->assertFalse($snap['public_cache']['effective_enabled']);
        $this->assertSame('disabled', $snap['status']);
        // Disabled is an operator choice, never "unavailable"/error.
        $this->assertNotSame('unavailable', $snap['status']);
    }

    public function test_snapshot_exposes_only_safe_driver_name_and_no_secrets(): void
    {
        $snap = $this->diagnostics()->snapshot();

        // Test env uses the array store; a safe operator-facing alias only.
        $this->assertSame('array', $snap['driver']);

        $json = json_encode($snap);
        foreach ([config('app.key'), 'APP_KEY', 'password', 'secret', base_path()] as $needle) {
            if (is_string($needle) && $needle !== '') {
                $this->assertStringNotContainsString($needle, (string) $json);
            }
        }
    }

    public function test_snapshot_lists_the_single_managed_domain_with_stable_id(): void
    {
        $snap = $this->diagnostics()->snapshot();

        $this->assertCount(1, $snap['domains']);
        $domain = $snap['domains'][0];
        $this->assertSame(PublicContentCacheManager::DOMAIN_ID, $domain['id']);
        $this->assertSame('public_content', $domain['id']);
        $this->assertTrue($domain['clearable']);
        $this->assertArrayHasKey('rebuildable', $domain);
        $this->assertArrayHasKey('label_key', $domain);
    }

    public function test_requesting_diagnostics_never_mutates_the_epoch(): void
    {
        $before = app('cms.public_cache')->version();

        $this->diagnostics()->snapshot();
        $this->diagnostics()->snapshot();

        $this->assertSame($before, app('cms.public_cache')->version(), 'Diagnostics must be read-only.');
    }

    // ---- Clear result (§15) --------------------------------------------------

    public function test_clear_result_advances_epoch_and_reports_before_after(): void
    {
        $policy = app('cms.cache_policy');
        $before = app('cms.public_cache')->version();

        $result = $policy->clearResult();

        $this->assertInstanceOf(CmsCacheOperationResult::class, $result);
        $this->assertSame('clear', $result->operation);
        $this->assertSame('public_content', $result->domain);
        $this->assertTrue($result->success);
        $this->assertSame($before, $result->before);
        $this->assertSame($before + 1, $result->after);
        $this->assertSame('optimize.clear.done', $result->messageKey);
    }

    public function test_clear_only_advances_the_epoch_and_leaves_unrelated_keys(): void
    {
        Cache::forever('unrelated.plugin.key', 'keep-me');

        app('cms.cache_policy')->clearResult();

        $this->assertSame('keep-me', Cache::get('unrelated.plugin.key'), 'Clear must not touch unrelated cache keys.');
    }
}
