<?php

declare(strict_types=1);

namespace Tests\Feature\Optimize;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\Cache\CmsRuntimeDiagnostics;
use TheNguyen\CMS\Services\CmsOptimizationPolicy;

/**
 * CORE-OPTIMIZE-3 §14/§18.12 — runtime optimization diagnostics are read-only,
 * secret-free and bounded. The public subset must never carry a driver name,
 * path, credential or token.
 */
class CmsRuntimeDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function diagnostics(): CmsRuntimeDiagnostics
    {
        return app('cms.runtime_diagnostics');
    }

    public function test_snapshot_has_expected_shape(): void
    {
        $snap = $this->diagnostics()->snapshot();

        $this->assertArrayHasKey('response', $snap);
        $this->assertArrayHasKey('static_assets', $snap);
        $this->assertArrayHasKey('media', $snap);
        $this->assertArrayHasKey('cache', $snap);
        $this->assertArrayHasKey('environment', $snap);
    }

    public function test_response_state_reflects_policy(): void
    {
        $this->assertSame('disabled', $this->diagnostics()->snapshot()['response']['status']);

        app('cms.settings')->set(CmsOptimizationPolicy::RESPONSE_ENABLED_KEY, true);

        $this->assertSame('healthy', $this->diagnostics()->snapshot()['response']['status']);
    }

    public function test_media_hints_are_theme_owned(): void
    {
        $media = $this->diagnostics()->snapshot()['media'];

        $this->assertSame('theme', $media['optimization_owner']);
        $this->assertSame('unavailable', $media['status']);
    }

    public function test_public_snapshot_is_secret_free(): void
    {
        $public = $this->diagnostics()->publicSnapshot();

        $encoded = json_encode($public);

        // No driver name, path, credential, token or key in the public subset.
        $this->assertArrayNotHasKey('driver', $public);
        $this->assertStringNotContainsString(base_path(), (string) $encoded);
        $this->assertStringNotContainsString('APP_KEY', (string) $encoded);
        $this->assertStringNotContainsString(':memory:', (string) $encoded);

        // Only the documented public keys.
        $this->assertSame([
            'response_optimization',
            'response_public_html_ttl',
            'response_status',
            'static_assets_fingerprinted',
            'static_assets_status',
        ], array_keys($public));
    }

    public function test_snapshot_is_read_only(): void
    {
        $epochBefore = app('cms.public_cache')->version();

        $this->diagnostics()->snapshot();
        $this->diagnostics()->publicSnapshot();

        $this->assertSame($epochBefore, app('cms.public_cache')->version(), 'Diagnostics must never mutate the cache epoch.');
    }
}
