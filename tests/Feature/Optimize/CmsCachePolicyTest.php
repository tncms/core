<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use TheNguyen\CMS\Models\Setting;
use TheNguyen\CMS\Services\CmsCachePolicy;

/**
 * CORE-OPTIMIZE-1 — canonical CMS Cache policy authority.
 *
 * Proves the policy contract (§26), infrastructure isolation (§27), targeted
 * Clear semantics (§29), and the §17 recursion boundary: the enabled flag stays
 * resolvable through cms_settings even when the cache layer it governs is empty.
 */
class CmsCachePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): CmsCachePolicy
    {
        return app('cms.cache_policy');
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    // ---- §26 Policy ----------------------------------------------------------

    public function test_the_binding_is_the_one_canonical_singleton(): void
    {
        $this->assertSame(app('cms.cache_policy'), app('cms.cache_policy'));
        $this->assertSame(app('cms.cache_policy'), app(CmsCachePolicy::class));
    }

    public function test_default_is_enabled_when_setting_absent(): void
    {
        $this->assertFalse($this->settings()->has(CmsCachePolicy::SETTING_KEY));
        $this->assertTrue($this->policy()->enabled());
        $this->assertFalse($this->policy()->disabled());
    }

    public function test_explicit_on_enables(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        $this->assertTrue($this->policy()->enabled());
        $this->assertFalse($this->policy()->disabled());
    }

    public function test_explicit_off_disables(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $this->assertFalse($this->policy()->enabled());
        $this->assertTrue($this->policy()->disabled());
    }

    public function test_reads_from_durable_settings_authority(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        // The value lives in the durable cms_settings table, not in-memory state.
        $row = Setting::query()->where('group', 'optimize')->where('key', 'cache_enabled')->first();
        $this->assertNotNull($row);
        $this->assertSame('boolean', $row->type);
        $this->assertSame('0', $row->value);
    }

    public function test_toggle_does_not_mutate_env_file(): void
    {
        $envPath = base_path('.env');
        $before = is_file($envPath) ? md5_file($envPath) : null;

        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        $this->policy()->clear();
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        $after = is_file($envPath) ? md5_file($envPath) : null;
        $this->assertSame($before, $after, '.env must never be written by the CMS Cache policy.');
    }

    public function test_toggle_does_not_change_cache_store_config(): void
    {
        $default = config('cache.default');
        $stores = config('cache.stores');

        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        $this->policy()->clear();

        $this->assertSame($default, config('cache.default'), 'Global cache store must be unchanged.');
        $this->assertSame($stores, config('cache.stores'));
        $this->assertSame($default, Cache::getDefaultDriver());
    }

    // ---- §27 / §29 Clear + infrastructure isolation -------------------------

    public function test_clear_returns_true_and_reports_success(): void
    {
        $this->assertTrue($this->policy()->clear());
    }

    public function test_clear_preserves_unrelated_cache_entries(): void
    {
        Cache::put('unrelated:key', 'survivor', 600);
        Cache::forever('another:unrelated', ['x' => 1]);

        $this->policy()->clear();

        $this->assertSame('survivor', Cache::get('unrelated:key'));
        $this->assertSame(['x' => 1], Cache::get('another:unrelated'));
    }

    public function test_clear_preserves_session_state(): void
    {
        session(['login_marker' => 'still-here']);

        $this->policy()->clear();

        $this->assertSame('still-here', session('login_marker'));
    }

    public function test_repeated_clear_is_idempotent_and_safe(): void
    {
        Cache::put('unrelated:key', 'survivor', 600);

        $this->assertTrue($this->policy()->clear());
        $this->assertTrue($this->policy()->clear());
        $this->assertTrue($this->policy()->clear());

        $this->assertSame('survivor', Cache::get('unrelated:key'));
    }

    public function test_clear_works_when_disabled(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);
        Cache::put('unrelated:key', 'survivor', 600);

        $this->assertTrue($this->policy()->clear());
        $this->assertSame('survivor', Cache::get('unrelated:key'));
    }

    // ---- §17 recursion boundary ---------------------------------------------

    public function test_policy_is_resolvable_when_optimization_cache_is_empty(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        // Simulate the optimization cache being wiped/cold. The policy must still
        // resolve from the durable settings authority (it never depends on the
        // very cache it controls).
        Cache::flush();

        $this->assertFalse($this->policy()->enabled());
    }
}
