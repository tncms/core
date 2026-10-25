<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\ExtensionTrust\Concerns\TrustedScriptFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\TrustedScriptApproval;
use TheNguyen\CMS\Models\TrustedScriptApprovalEvent;
use TheNguyen\CMS\Services\TrustedScriptApprovalStore;

/**
 * CORE-EXTENSION-TRUST S1 — Gates D/E/F registry behaviour.
 * Matrix: T5, T10, T11, T14, T18; N1, N2, N3, N7, N8, N10; request isolation.
 */
final class TrustedScriptRegistryTest extends TestCase
{
    use RefreshDatabase;
    use TrustedScriptFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFixturePlugins();
        $this->cleanFixturePlugins();
        Http::preventStrayRequests();
    }

    private function footerBucket(): string
    {
        return app('cms.assets')->renderFrontendScripts();
    }

    private function headBucket(): string
    {
        return app('cms.assets')->renderFrontendStyles();
    }

    private function setupApprovedAlpha(): object
    {
        $this->writePlugin('ts-alpha', ['turnstile' => $this->turnstileEntry()]);
        $this->activate('ts-alpha');
        $this->approve('ts-alpha', 'turnstile');

        return $this->caller('ts-alpha');
    }

    public function test_bindings_exist(): void
    {
        $this->assertInstanceOf(\TheNguyen\CMS\Services\TrustedExternalScriptRegistry::class, app('cms.trusted_scripts'));
        $this->assertInstanceOf(TrustedScriptApprovalStore::class, app('cms.trusted_script_approvals'));
    }

    public function test_approved_owner_enqueue_renders_the_script_once_in_the_footer(): void
    {
        $caller = $this->setupApprovedAlpha();

        $this->assertTrue($caller->enqueue('turnstile'));
        $this->assertTrue($caller->enqueue('turnstile'));

        $html = $this->footerBucket();

        $this->assertSame(1, substr_count($html, self::TURNSTILE));
        $this->assertStringContainsString('<script src="'.self::TURNSTILE.'" async defer></script>', $html);
        $this->assertStringNotContainsString(self::TURNSTILE, $this->headBucket());
        $this->assertTrue($this->registry()->renderedThisRequest());
    }

    public function test_head_position_renders_in_head_bucket(): void
    {
        $this->writePlugin('ts-head', ['w' => $this->turnstileEntry(['position' => 'head'])]);
        $this->activate('ts-head');
        $this->approve('ts-head', 'w');

        $this->assertTrue($this->caller('ts-head')->enqueue('w'));
        $this->assertStringContainsString(self::TURNSTILE, $this->headBucket());
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_declaration_alone_never_renders(): void
    {
        $this->setupApprovedAlpha();

        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
        $this->assertFalse($this->registry()->renderedThisRequest());
    }

    public function test_n1_foreign_plugin_cannot_enqueue_another_plugins_script(): void
    {
        $this->setupApprovedAlpha();
        $this->writePlugin('ts-beta', []);
        $this->activate('ts-beta');

        $beta = $this->caller('ts-beta');

        // Beta asks for "turnstile" with its own identity: Beta declares nothing.
        $this->assertFalse($beta->enqueue('turnstile'));
        // Beta hands over an Alpha object: call site is Beta's file => mismatch.
        $this->assertFalse($beta->enqueueAs($this->caller('ts-alpha'), 'turnstile'));

        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());

        $reasons = array_column($this->registry()->diagnostics()['denied'], 'reason');
        $this->assertContains('unknown or invalid declaration', $reasons);
        $this->assertContains('owner mismatch', $reasons);
    }

    public function test_n1_spoof_laundered_through_the_container_is_refused(): void
    {
        $this->setupApprovedAlpha();
        $this->writePlugin('ts-launder', []);
        $this->activate('ts-launder');

        $this->assertFalse($this->caller('ts-launder')->enqueueAsViaContainer($this->caller('ts-alpha'), 'turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
        $this->assertContains('owner mismatch', array_column($this->registry()->diagnostics()['denied'], 'reason'));
    }

    public function test_owner_code_triggered_by_another_plugin_is_allowed(): void
    {
        // e.g. a page builder (plugin) renders a component whose provider (owner)
        // enqueues its own script: the nearest plugin frame is the owner's.
        $this->setupApprovedAlpha();
        $this->writePlugin('ts-builder', []);
        $this->activate('ts-builder');

        $this->assertTrue($this->caller('ts-builder')->triggerOther($this->caller('ts-alpha'), 'turnstile'));
        $this->assertSame(1, substr_count($this->footerBucket(), self::TURNSTILE));
    }

    public function test_caller_outside_any_plugin_is_refused(): void
    {
        $this->setupApprovedAlpha();

        $this->assertFalse($this->registry()->enqueue($this, 'turnstile'));
        $this->assertFalse(cms_enqueue_trusted_script(new \stdClass, 'turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_n2_inactive_plugin_declaration_is_invisible(): void
    {
        $this->writePlugin('ts-off', ['turnstile' => $this->turnstileEntry()]);

        $this->assertNull($this->registry()->declaration('ts-off', 'turnstile'));
        $this->assertFalse($this->caller('ts-off')->enqueue('turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_unapproved_declaration_is_blocked(): void
    {
        $this->writePlugin('ts-pending', ['turnstile' => $this->turnstileEntry()]);
        $this->activate('ts-pending');

        $this->assertSame(TrustedScriptApprovalStore::STATE_PENDING, app('cms.trusted_script_approvals')->state($this->declarationFor('ts-pending', 'turnstile')));
        $this->assertFalse($this->caller('ts-pending')->enqueue('turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
        $this->assertContains('not approved', array_column($this->registry()->diagnostics()['denied'], 'reason'));
    }

    public function test_n10_declaration_change_invalidates_approval_until_reapproved(): void
    {
        $caller = $this->setupApprovedAlpha();

        $this->writePlugin('ts-alpha', ['turnstile' => $this->turnstileEntry([
            'csp' => ['script-src' => ['https://challenges.cloudflare.com'], 'connect-src' => ['https://collector.example.com']],
        ])]);
        $this->freshRequest();

        $declaration = $this->declarationFor('ts-alpha', 'turnstile');
        $this->assertSame(TrustedScriptApprovalStore::STATE_CHANGED, app('cms.trusted_script_approvals')->state($declaration));
        $this->assertFalse($caller->enqueue('turnstile'));
        $this->assertContains('approval stale: declaration changed', array_column($this->registry()->diagnostics()['denied'], 'reason'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());

        $this->approve('ts-alpha', 'turnstile');
        $this->assertTrue($caller->enqueue('turnstile'));
        $this->assertStringContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_n3_deactivation_revokes_and_reactivation_requires_fresh_approval(): void
    {
        $caller = $this->setupApprovedAlpha();
        $this->assertTrue($caller->enqueue('turnstile'));
        $this->assertStringContainsString(self::TURNSTILE, $this->footerBucket());

        app('cms.extension')->deactivatePlugin('ts-alpha');
        $this->freshRequest();

        $this->assertFalse($caller->enqueue('turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());

        $row = TrustedScriptApproval::query()->where('owner_slug', 'ts-alpha')->firstOrFail();
        $this->assertSame('revoked', $row->status);
        $this->assertSame('plugin_deactivated', $row->revocation_reason);
        $this->assertTrue(TrustedScriptApprovalEvent::query()->where('action', 'revoked_plugin_deactivated')->exists());

        // Reactivation with the SAME manifest still needs an explicit approval.
        $this->activate('ts-alpha');
        $this->freshRequest();
        $this->assertSame(TrustedScriptApprovalStore::STATE_REVOKED, app('cms.trusted_script_approvals')->state($this->declarationFor('ts-alpha', 'turnstile')));
        $this->assertFalse($caller->enqueue('turnstile'));
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());

        $this->approve('ts-alpha', 'turnstile');
        $this->assertTrue($caller->enqueue('turnstile'));
    }

    public function test_admin_revocation_takes_effect_in_the_same_request(): void
    {
        $caller = $this->setupApprovedAlpha();
        $this->assertTrue($caller->enqueue('turnstile'));

        $this->assertTrue(app('cms.trusted_script_approvals')->revoke('ts-alpha', 'turnstile', 7));

        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
        $event = TrustedScriptApprovalEvent::query()->where('action', 'revoked')->firstOrFail();
        $this->assertSame(7, $event->actor_id);
    }

    public function test_n7_reserved_ext_handles_are_refused_for_ordinary_registrations(): void
    {
        $assets = app('cms.assets');

        $this->assertFalse($assets->registerScript('ext:ts-alpha/turnstile', '/evil.js'));
        $this->assertFalse($assets->registerScript('EXT:ts-alpha/turnstile', '/evil.js'));
        $this->assertFalse($assets->registerStyle('ext:a/b', '/x.css'));
        $this->assertFalse($assets->registerMarker('ext:a/b'));
        $this->assertFalse($assets->inlineScript('ext:a/b', 'window.x = 1;'));
        $this->assertFalse($assets->enqueueScript('ext:ts-alpha/turnstile'));
        $this->assertContains('reserved handle prefix', array_column($assets->rejected(), 'reason'));

        // An ordinary enqueue of the reserved handle never activates the script.
        $this->setupApprovedAlpha();
        enqueue_script('ext:ts-alpha/turnstile');
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_n8_same_url_from_two_approved_owners_renders_once_and_dependents_resolve(): void
    {
        $this->setupApprovedAlpha();
        $this->writePlugin('ts-gamma', ['captcha' => $this->turnstileEntry()]);
        $this->activate('ts-gamma');
        $this->approve('ts-gamma', 'captcha');

        $this->assertTrue($this->caller('ts-alpha')->enqueue('turnstile'));
        $this->assertTrue($this->caller('ts-gamma')->enqueue('captcha'));

        // Gamma's own callback depends on Gamma's handle.
        app('cms.assets')->registerScript('gamma-boot', '/gamma-boot.js', ['ext:ts-gamma/captcha']);
        enqueue_script('gamma-boot');

        $html = $this->footerBucket();

        $this->assertSame(1, substr_count($html, self::TURNSTILE));
        $this->assertLessThan(strpos($html, '/gamma-boot.js'), strpos($html, self::TURNSTILE));
        $notes = array_column($this->registry()->diagnostics()['notes'], 'reason');
        $this->assertContains('duplicate url: rendered once via ext:ts-alpha/turnstile', $notes);

        // Ownership and approval stay separate per owner.
        $this->assertNotSame(
            $this->declarationFor('ts-alpha', 'turnstile')->fingerprint,
            $this->declarationFor('ts-gamma', 'captcha')->fingerprint,
        );
    }

    public function test_dependency_ordering_with_ordinary_assets(): void
    {
        app('cms.assets')->registerScript('tnc-lib', '/plugins/x/lib.js');
        $this->writePlugin('ts-deps', ['w' => $this->turnstileEntry(['deps' => ['tnc-lib']])]);
        $this->activate('ts-deps');
        $this->approve('ts-deps', 'w');
        $this->assertTrue($this->caller('ts-deps')->enqueue('w'));
        app('cms.assets')->inlineScript('tnc-ready', 'window.tncReady = function () {};', 'ext:ts-deps/w');

        $html = $this->footerBucket();

        $lib = strpos($html, '/plugins/x/lib.js');
        $inline = strpos($html, 'window.tncReady');
        $script = strpos($html, self::TURNSTILE);
        $this->assertNotFalse($lib);
        $this->assertNotFalse($inline);
        $this->assertTrue($lib < $inline && $inline < $script, 'lib -> before-inline -> trusted script');
    }

    public function test_cross_extension_keys_never_collide(): void
    {
        $this->writePlugin('ts-one', ['widget' => $this->turnstileEntry()]);
        $this->writePlugin('ts-two', ['widget' => $this->turnstileEntry(['url' => 'https://cdn.example.com/widget.js', 'csp' => ['script-src' => ['https://cdn.example.com']]])]);
        $this->activate('ts-one', 'ts-two');
        $this->approve('ts-two', 'widget');

        $this->assertFalse($this->caller('ts-one')->enqueue('widget'));
        $this->assertTrue($this->caller('ts-two')->enqueue('widget'));

        $html = $this->footerBucket();
        $this->assertStringContainsString('https://cdn.example.com/widget.js', $html);
        $this->assertStringNotContainsString(self::TURNSTILE, $html);
    }

    public function test_request_isolation_enqueue_does_not_leak_to_the_next_request(): void
    {
        $caller = $this->setupApprovedAlpha();

        $this->freshRequest('/one');
        $this->assertTrue($caller->enqueue('turnstile'));
        $this->assertStringContainsString(self::TURNSTILE, $this->footerBucket());
        $this->assertTrue($this->registry()->renderedThisRequest());

        $this->freshRequest('/two');
        $this->assertSame([], $this->registry()->diagnostics()['enqueued']);
        $this->assertFalse($this->registry()->renderedThisRequest());
        $this->assertTrue($this->registry()->cspRequirements()->isEmpty());
        $this->assertStringNotContainsString(self::TURNSTILE, $this->footerBucket());
    }

    public function test_csp_requirements_are_reported_not_emitted(): void
    {
        $caller = $this->setupApprovedAlpha();
        $this->writePlugin('ts-pend', ['x' => $this->turnstileEntry(['url' => 'https://cdn.example.com/x.js', 'csp' => ['script-src' => ['https://cdn.example.com']]])]);
        $this->activate('ts-pend');

        $this->assertTrue($this->registry()->cspRequirements()->isEmpty());

        $caller->enqueue('turnstile');

        $this->assertSame([
            'frame-src' => ['https://challenges.cloudflare.com'],
            'script-src' => ['https://challenges.cloudflare.com'],
        ], $this->registry()->cspRequirements()->toArray());

        // $all = every APPROVED declaration (the pending one is excluded).
        $this->assertSame(['https://challenges.cloudflare.com'], $this->registry()->cspRequirements(true)->sourcesFor('script-src'));
    }

    public function test_invalid_declarations_are_reported_and_never_enqueue(): void
    {
        $this->writePlugin('ts-bad', ['evil' => $this->turnstileEntry(['url' => 'http://challenges.cloudflare.com/x.js'])]);
        $this->activate('ts-bad');

        $this->assertNull($this->registry()->declaration('ts-bad', 'evil'));
        $this->assertSame([['owner' => 'ts-bad', 'key' => 'evil', 'reason' => 'invalid url']], $this->registry()->invalidDeclarations());
        $this->assertFalse($this->caller('ts-bad')->enqueue('evil'));
    }

    public function test_health_snapshot_reports_counts_only(): void
    {
        $this->setupApprovedAlpha();
        $this->writePlugin('ts-pend2', ['x' => $this->turnstileEntry()]);
        $this->writePlugin('ts-bad2', ['x' => $this->turnstileEntry(['csp' => ['script-src' => ['*']]])]);
        $this->activate('ts-pend2', 'ts-bad2');

        $snapshot = $this->registry()->healthSnapshot();

        $this->assertSame([
            'trusted_scripts_ready' => true,
            'trusted_script_declared_count' => 2,
            'trusted_script_approved_count' => 1,
            'trusted_script_pending_count' => 1,
            'trusted_script_invalid_count' => 1,
        ], $snapshot);

        $json = (string) $this->get('/cms-health')->getContent();
        $this->assertStringContainsString('"trusted_script_declared_count":2', $json);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $json);
        $this->assertStringNotContainsString($this->declarationFor('ts-alpha', 'turnstile')->fingerprint, $json);
    }

    public function test_t14_no_outbound_http_and_no_http_client_in_trust_code(): void
    {
        $caller = $this->setupApprovedAlpha();
        $caller->enqueue('turnstile');
        $this->footerBucket();
        $this->registry()->cspRequirements(true);

        Http::assertNothingSent();

        $files = [
            'src/Services/TrustedExternalScriptRegistry.php',
            'src/Services/TrustedScriptApprovalStore.php',
            'src/Services/ExtensionIdentityResolver.php',
            'src/Support/TrustedScripts/StrictHttpsUrl.php',
            'src/Support/TrustedScripts/TrustedScriptManifestParser.php',
            'src/Support/TrustedScripts/TrustedScriptDeclaration.php',
            'src/Support/TrustedScripts/JsonDuplicateKeys.php',
            'src/Support/TrustedScripts/CspRequirements.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(base_path('packages/thenguyen/cms-core/'.$file));

            foreach (['Http::', 'curl_', 'file_get_contents(', 'fopen(', 'fsockopen', 'gethostbyname', 'dns_get_record', 'stream_socket_client', 'GuzzleHttp'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file} must not contain {$needle}");
            }
        }
    }

    public function test_enqueue_never_throws_on_garbage_keys(): void
    {
        $caller = $this->setupApprovedAlpha();

        foreach (['', 'UPPER', '../x', "a\0b", str_repeat('k', 200), 'turnstile ', 'ext:ts-alpha/turnstile'] as $key) {
            $this->assertFalse($caller->enqueue($key));
        }
    }
}
