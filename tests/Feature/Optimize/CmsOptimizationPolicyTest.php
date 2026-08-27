<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;
use TheNguyen\CMS\Services\SettingsManager;

/**
 * CORE-OPTIMIZE-3 §18.1/§18.2 — runtime response-optimization policy defaults and
 * durable settings persistence. Defaults must be conservative (OFF, TTL 0) and
 * the TTL must be clamped so a bad stored value can never produce a negative or
 * unbounded browser cache lifetime.
 */
class CmsOptimizationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): CmsOptimizationPolicy
    {
        return app('cms.optimization_policy');
    }

    private function settings(): SettingsManager
    {
        return app('cms.settings');
    }

    public function test_defaults_are_conservative(): void
    {
        $this->assertFalse($this->policy()->responseOptimizationEnabled());
        $this->assertSame(0, $this->policy()->publicHtmlTtl());
    }

    public function test_enabled_reads_durable_setting(): void
    {
        $this->settings()->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);

        $this->assertTrue($this->policy()->responseOptimizationEnabled());
    }

    public function test_ttl_reads_durable_setting(): void
    {
        $this->settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 300);

        $this->assertSame(300, $this->policy()->publicHtmlTtl());
    }

    public function test_negative_ttl_clamps_to_zero(): void
    {
        $this->settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, -50);

        $this->assertSame(0, $this->policy()->publicHtmlTtl());
    }

    public function test_oversized_ttl_clamps_to_max(): void
    {
        $this->settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 999999);

        $this->assertSame(CmsOptimizationPolicy::TTL_MAX, $this->policy()->publicHtmlTtl());
    }

    public function test_non_numeric_ttl_falls_back_to_default(): void
    {
        $this->settings()->set(CmsOptimizationPolicy::RESPONSE_PUBLIC_TTL_KEY, 'not-a-number');

        $this->assertSame(0, $this->policy()->publicHtmlTtl());
    }
}
