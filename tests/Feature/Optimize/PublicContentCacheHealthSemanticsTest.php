<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\CmsCachePolicy;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * CORE-OPTIMIZE-2 §8 — PublicContentCacheManager::health() must report the
 * EFFECTIVE runtime state, not merely the TTL configuration.
 *
 * CORE-OPTIMIZE-1 left health()['enabled'] == (ttl > 0), which stays true even
 * when the operator has turned CMS Cache OFF through the policy — an ambiguous
 * value that means "configured" while claiming to mean "enabled". These tests
 * pin the corrected, unambiguous semantics.
 */
class PublicContentCacheHealthSemanticsTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): PublicContentCacheManager
    {
        return app('cms.public_cache');
    }

    private function settings(): \TheNguyen\CMS\Services\SettingsManager
    {
        return app('cms.settings');
    }

    public function test_health_distinguishes_policy_ttl_and_effective_state(): void
    {
        $health = $this->manager()->health();

        $this->assertArrayHasKey('policy_enabled', $health);
        $this->assertArrayHasKey('configured_ttl', $health);
        $this->assertArrayHasKey('effective_enabled', $health);
        $this->assertArrayHasKey('epoch', $health);
        $this->assertArrayHasKey('status', $health);
    }

    public function test_effective_enabled_is_false_when_policy_off_despite_positive_ttl(): void
    {
        // Positive TTL is configured (default 3600) but the operator disables CMS Cache.
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, false);

        $health = $this->manager()->health();

        $this->assertGreaterThan(0, $health['configured_ttl'], 'TTL is still configured positive');
        $this->assertFalse($health['policy_enabled'], 'policy is OFF');
        $this->assertFalse($health['effective_enabled'], 'effective must reflect the OFF policy');
        // The legacy `enabled` key must now mean EFFECTIVE, not ttl>0.
        $this->assertFalse($health['enabled'], 'legacy enabled must reflect effective state, not TTL config');
        $this->assertSame('disabled', $health['status']);
    }

    public function test_effective_enabled_is_true_when_policy_on_and_ttl_positive(): void
    {
        $this->settings()->set(CmsCachePolicy::SETTING_KEY, true);

        $health = $this->manager()->health();

        $this->assertTrue($health['policy_enabled']);
        $this->assertGreaterThan(0, $health['configured_ttl']);
        $this->assertTrue($health['effective_enabled']);
        $this->assertTrue($health['enabled']);
        $this->assertSame('healthy', $health['status']);
    }
}
