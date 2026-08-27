<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Services\CmsCachePolicy;
use TheNguyen\CMS\Update\UpdateAvailability;
use TheNguyen\CMS\Update\UpdateStateStore;

/**
 * CORE-OPTIMIZE-2 §7/§27/§38/§39/§47/§48/§51 — isolation & degradation.
 *
 * Proves the central invariant: CMS cache operations are targeted and never
 * touch unrelated infrastructure (update state, installer state, sessions, other
 * cache keys), and a cache backend failure degrades to source-of-truth instead
 * of taking down content.
 */
class CmsCacheIsolationTest extends TestCase
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

    // ---- §38 Update-state isolation (mandatory) ------------------------------

    public function test_cache_operations_do_not_touch_update_state_or_unrelated_keys(): void
    {
        // 1. Persist updater check/download/verification state.
        $store = new UpdateStateStore;
        $store->recordCheck(UpdateAvailability::unavailable('1.0.0-beta.7.1.21', 'stable', 'disabled'));
        $store->recordDownload(['bytes' => 123, 'target_version' => '9.9.9']);
        $store->recordVerification(['ok' => true]);
        $this->assertFileExists($store->file());
        $updateBytesBefore = file_get_contents($store->file());

        // 2. An unrelated (e.g. plugin) cache key.
        Cache::forever('plugin.acme.key', 'survivor');

        // 3. Diagnostics, 4. Clear, 5. Rebuild.
        Slug::create(['reference_type' => 'content', 'reference_id' => 1, 'locale' => 'en', 'slug' => 'about', 'prefix' => null, 'full_path' => 'about', 'is_primary' => true]);
        app('cms.cache_diagnostics')->snapshot();
        $this->policy()->clearResult();
        $this->policy()->rebuild();

        // 6. Update state byte-identical. 7. Unrelated key survives.
        $this->assertSame($updateBytesBefore, file_get_contents($store->file()), 'UpdateStateStore must be byte-unchanged.');
        $this->assertSame('survivor', Cache::get('plugin.acme.key'), 'Unrelated cache key must survive.');
    }

    // ---- §7/§40 No global flush ---------------------------------------------

    public function test_clear_and_rebuild_never_globally_flush_the_store(): void
    {
        foreach (range(1, 20) as $i) {
            Cache::forever("infra.key.$i", "v$i");
        }
        Slug::create(['reference_type' => 'content', 'reference_id' => 1, 'locale' => 'en', 'slug' => 'x', 'prefix' => null, 'full_path' => 'x', 'is_primary' => true]);

        $this->policy()->clearResult();
        $this->policy()->rebuild();

        foreach (range(1, 20) as $i) {
            $this->assertSame("v$i", Cache::get("infra.key.$i"), "infra.key.$i must survive a targeted clear.");
        }
    }

    // ---- §39 Installer isolation --------------------------------------------

    public function test_cache_operations_do_not_touch_installer_marker(): void
    {
        $marker = storage_path('app/tncms-installed');
        @mkdir(dirname($marker), 0775, true);
        file_put_contents($marker, 'installed');

        $this->policy()->clearResult();
        $this->policy()->rebuild();

        $this->assertFileExists($marker);
        $this->assertSame('installed', file_get_contents($marker));

        @unlink($marker);
    }

    // ---- §27 SettingsManager recursion boundary -----------------------------

    public function test_policy_flag_stays_resolvable_after_clear(): void
    {
        // Clearing the public cache must never make the policy flag (read through
        // SettingsManager) unresolvable — no policy -> settings-cache -> policy loop.
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        $this->policy()->clearResult();

        $this->assertFalse($this->policy()->enabled(), 'Policy flag must resolve OFF after a clear.');

        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);
        $this->assertTrue($this->policy()->enabled());
    }

    // ---- §47/§48/§51 Cache backend failure is degradation, not data loss -----

    public function test_store_failure_degrades_diagnostics_but_content_still_resolves(): void
    {
        // A cache backend that throws on every operation.
        Cache::swap(new class
        {
            public function __call($name, $arguments)
            {
                throw new RuntimeException('cache backend down');
            }
        });

        // Content still resolves from the authoritative source (resolver runs).
        $calls = 0;
        $ref = app('cms.public_cache')->rememberResolution('en', 'about', function () use (&$calls) {
            $calls++;

            return ['reference_type' => 'content', 'reference_id' => 5];
        });
        $this->assertSame(1, $calls, 'Resolver must run when the cache store is down.');
        $this->assertSame(['reference_type' => 'content', 'reference_id' => 5], $ref);

        // Diagnostics report the failure honestly (unavailable), never a fake healthy.
        $snap = app('cms.cache_diagnostics')->snapshot();
        $this->assertSame('unavailable', $snap['status']);

        // Clear cannot prove invalidation, so it reports failure honestly.
        $result = app('cms.cache_policy')->clearResult();
        $this->assertFalse($result->success);
        $this->assertSame('optimize.clear.failed', $result->messageKey);
    }
}
