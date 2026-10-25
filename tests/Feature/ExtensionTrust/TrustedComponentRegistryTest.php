<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ExtensionTrust\Concerns\TrustedComponentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * CORE-EXTENSION-TRUST S2 — provider registration authority, invocation
 * authority, lifecycle, limits and fail-closed behaviour (brief §3.1/§3.2,
 * matrix T10/T11/T12/T16/T21).
 */
final class TrustedComponentRegistryTest extends TestCase
{
    use RefreshDatabase;
    use TrustedComponentFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useComponentFixturePlugins();
        $this->writeComponentPlugin('s2-alpha');
        $this->writeComponentPlugin('s2-beta');
        $this->activateComponentPlugins('s2-alpha', 's2-beta');
        $this->freshSlotRequest();
    }

    private function reasons(): array
    {
        return array_map(fn (array $d) => $d['reason'], $this->components()->diagnostics());
    }

    private function registerAlpha(mixed ...$args): object
    {
        $provider = $this->provider('s2-alpha', ...$args);
        $this->assertTrue($this->pluginCode('s2-alpha')->register($provider), implode(' | ', $this->reasons()));

        return $provider;
    }

    private function vaultHtml(string $placeholder): string
    {
        return TrustedSlotVault::forRequest(request())->resolve($placeholder, insert: true)['body'];
    }

    // --- registration authority -------------------------------------------------

    public function test_owner_plugin_registers_its_own_provider_under_its_namespace(): void
    {
        $this->registerAlpha('form', '<p>hello</p>');

        $this->assertTrue($this->components()->has('s2-alpha/form'));
        $this->assertSame(['s2-alpha/form'], $this->components()->ids());
    }

    public function test_registration_from_non_plugin_code_is_rejected(): void
    {
        // The test itself is host code: no plugin frame on the stack.
        $this->assertFalse($this->components()->register($this->provider('s2-alpha', 'form')));
        $this->assertContains('registration must come from the owner plugin', $this->reasons());
        $this->assertFalse($this->components()->has('s2-alpha/form'));
    }

    public function test_spoof_another_plugin_registering_a_foreign_provider_is_rejected(): void
    {
        $foreign = $this->provider('s2-alpha', 'form');

        $this->assertFalse($this->pluginCode('s2-beta')->register($foreign));
        $this->assertFalse($this->pluginCode('s2-beta')->registerViaContainer($foreign));
        $this->assertSame(['owner mismatch', 'owner mismatch'], $this->reasons());
        $this->assertSame([], $this->components()->ids());
    }

    public function test_spoof_inactive_owner_and_unowned_provider_are_rejected(): void
    {
        app('cms.extension')->deactivatePlugin('s2-alpha');
        app('cms.extension')->flushRegistry();
        $this->assertFalse($this->pluginCode('s2-alpha')->register($this->provider('s2-alpha', 'form')));

        // An anonymous class defined in host (test) code has no plugin owner.
        $anonymous = new class implements \TheNguyen\CMS\Contracts\TrustedComponentProvider
        {
            public function key(): string
            {
                return 'anon';
            }

            public function policy(): \TheNguyen\CMS\Support\TrustedComponents\TrustedComponentPolicy
            {
                return new \TheNguyen\CMS\Support\TrustedComponents\TrustedComponentPolicy(['public']);
            }

            public function render(\TheNguyen\CMS\Support\TrustedComponents\TrustedRenderContext $context, array $props): string
            {
                return '<p>x</p>';
            }
        };
        $this->assertFalse($this->pluginCode('s2-beta')->register($anonymous));

        $this->assertSame(['provider not owned by an active plugin', 'provider not owned by an active plugin'], $this->reasons());
    }

    public function test_duplicate_invalid_key_and_invalid_policy_are_rejected(): void
    {
        $code = $this->pluginCode('s2-alpha');
        $this->registerAlpha('form');

        $this->assertFalse($code->register($this->provider('s2-alpha', 'form')));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'Bad Key')));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k1', '<p>x</p>', [])));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k2', '<p>x</p>', ['public', 'admin'])));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k3', '<p>x</p>', ['public'], ['Not A Slug'])));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k4', '<p>x</p>', ['public'], [], 'bad permission!')));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k5', '<p>x</p>', ['public'], [], null, ['onclick'])));
        $this->assertFalse($code->register($this->provider('s2-alpha', 'k6', '<p>x</p>', ['public'], [], null, ['data-tncms-slot'])));

        $this->assertSame([
            'duplicate component', 'invalid key',
            'invalid policy: contexts', 'invalid policy: contexts', 'invalid policy: invokers',
            'invalid policy: permission', 'invalid policy: data attributes', 'invalid policy: data attributes',
        ], $this->reasons());
        $this->assertSame(['s2-alpha/form'], $this->components()->ids());
    }

    public function test_registry_freezes_at_the_first_matched_route(): void
    {
        $this->firstGetRoute('/s2-freeze-probe', fn () => 'ok', ['web']);
        $this->assertFalse($this->components()->isFrozen());

        $this->get('/s2-freeze-probe')->assertOk();

        $this->assertTrue($this->components()->isFrozen());
        $this->assertFalse($this->pluginCode('s2-alpha')->register($this->provider('s2-alpha', 'late')));
        $this->assertContains('registry frozen', $this->reasons());
    }

    // --- invocation authority ---------------------------------------------------

    public function test_owner_invocation_mints_a_placeholder_holding_the_normalized_html(): void
    {
        $this->registerAlpha('form', '<p class="x">Hi {prop:name}</p>');

        $placeholder = $this->pluginCode('s2-alpha')->invoke('s2-alpha/form', ['name' => '"><script>alert(1)</script>']);

        $this->assertMatchesRegularExpression('#^<div class="tncms-slot">TNCMSSLOT[0-9a-f]{32}</div>$#', $placeholder);
        $this->assertSame('<p class="x">Hi "&gt;&lt;script&gt;alert(1)&lt;/script&gt;</p>', $this->vaultHtml($placeholder));
    }

    public function test_no_resolver_on_the_route_returns_the_fallback(): void
    {
        $this->registerAlpha('form');
        app()->instance('request', Request::create('/no-middleware'));

        $this->assertSame(TrustedSlotVault::FALLBACK, $this->pluginCode('s2-alpha')->invoke('s2-alpha/form'));
        $this->assertContains('no slot resolver on this route', $this->reasons());
    }

    public function test_unknown_component_and_deactivated_owner_fail_closed(): void
    {
        $alpha = $this->registerAlpha('form');
        $code = $this->pluginCode('s2-alpha');

        $this->assertSame(TrustedSlotVault::FALLBACK, $code->invoke('s2-alpha/missing'));
        $this->assertSame(TrustedSlotVault::FALLBACK, $code->invoke('not an id'));

        app('cms.extension')->deactivatePlugin('s2-alpha');
        app('cms.extension')->flushRegistry();
        $this->assertSame(TrustedSlotVault::FALLBACK, $this->pluginCode('s2-beta')->invoke('s2-alpha/form'));
        $this->assertSame([], $alpha->calls);

        $this->assertSame(['unknown component', 'unknown component', 'owner inactive'], $this->reasons());
    }

    public function test_invoker_policy_owner_listed_plugin_and_host(): void
    {
        $this->registerAlpha('own', '<p>own</p>');
        $this->registerAlpha('shared', '<p>shared</p>', ['public'], ['s2-beta']);
        $this->registerAlpha('hosted', '<p>hosted</p>', ['public'], ['@host']);
        $beta = $this->pluginCode('s2-beta');

        $this->assertSame(TrustedSlotVault::FALLBACK, $beta->invoke('s2-alpha/own'));
        $this->assertSame(TrustedSlotVault::FALLBACK, $beta->invokeViaContainer('s2-alpha/own'));
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/own'));
        $this->assertSame(TrustedSlotVault::FALLBACK, $beta->invoke('s2-alpha/hosted'));

        $this->assertSame('<p>shared</p>', $this->vaultHtml($beta->invoke('s2-alpha/shared')));
        $this->assertSame('<p>hosted</p>', $this->vaultHtml(cms_trusted_component('s2-alpha/hosted')));
        $this->assertSame('<p>own</p>', $this->vaultHtml($this->pluginCode('s2-alpha')->invoke('s2-alpha/own')));

        $this->assertSame(array_fill(0, 4, 'invoker not allowed'), $this->reasons());
    }

    public function test_context_is_server_derived_and_carries_no_private_objects(): void
    {
        $provider = $this->registerAlpha('ctx', '', ['public', 'preview'], ['@host'], null, [], 'context');

        $html = $this->vaultHtml(cms_trusted_component('s2-alpha/ctx'));
        $this->assertSame('<p class="ctx">'.json_encode(['public', '@host', app()->getLocale(), false, null]).'</p>', $html);

        $ref = new \ReflectionClass(\TheNguyen\CMS\Support\TrustedComponents\TrustedRenderContext::class);
        foreach ($ref->getProperties() as $property) {
            $type = (string) $property->getType();
            $this->assertMatchesRegularExpression('/^\??(string|int|bool)$/', $type, $property->getName().' must be scalar');
        }

        $this->assertCount(1, $provider->calls);
    }

    public function test_requested_mode_can_only_be_stricter_than_the_server_mode(): void
    {
        $this->registerAlpha('pub', '<p>{mode}</p>', ['public'], ['@host']);
        $this->registerAlpha('all', '<p>{mode}</p>', ['public', 'preview', 'editor'], ['@host']);

        $this->assertSame('<p>editor</p>', $this->vaultHtml(cms_trusted_component('s2-alpha/all', [], 'editor')));
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/pub', [], 'preview'));
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/pub', [], 'god'));
        $this->assertSame(['context not allowed', 'invalid mode'], $this->reasons());

        // Under a signed preview, asking for "public" cannot downgrade the mode.
        $this->freshSlotRequest('/', ['cms_preview' => true]);
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/pub', [], 'public'));
        $this->assertSame('<p>preview</p>', $this->vaultHtml(cms_trusted_component('s2-alpha/all', [], 'public')));
        $this->assertSame(['context not allowed'], $this->reasons());
    }

    public function test_props_must_be_bounded_scalars(): void
    {
        $this->registerAlpha('p', '<p>ok</p>', ['public'], ['@host']);

        $bad = [
            ['o' => new \stdClass],
            ['c' => fn () => 1],
            ['deep' => ['a' => ['b' => ['c' => ['d' => 1]]]]],
            ['long' => str_repeat('x', 4097)],
            array_fill_keys(array_map(fn ($i) => 'k'.$i, range(1, 65)), 1),
            ['bad key!' => 1],
        ];

        foreach ($bad as $props) {
            $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/p', $props));
        }

        $this->assertSame(array_fill(0, count($bad), 'invalid props'), $this->reasons());
        $this->assertSame('<p>ok</p>', $this->vaultHtml(cms_trusted_component('s2-alpha/p', ['a' => 'x', 'n' => 1, 'b' => true, 'z' => null, 'list' => ['x', 'y']])));
    }

    // --- failure handling -------------------------------------------------------

    public function test_render_exception_is_contained_and_logged_without_its_message(): void
    {
        Log::spy();
        $this->registerAlpha('boom', '', ['public'], ['@host'], null, [], 'throw');

        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/boom'));
        $this->assertSame(['render failed'], $this->reasons());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $flat = $message.json_encode($context);

            return str_contains($flat, 'RuntimeException') && ! str_contains($flat, 'hunter2') && ! str_contains($flat, 'S2-SECRET');
        })->once();
    }

    public function test_profile_violation_rejects_the_whole_component(): void
    {
        $this->registerAlpha('xss', '<form method="post" action="/x"><input type="text" name="a"></form><script>alert(1)</script>', ['public'], ['@host']);

        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/xss'));
        $this->assertSame(['profile violation: element'], $this->reasons());
        $this->assertSame(0, TrustedSlotVault::forRequest(request())->mintedCount());
    }

    public function test_form_actions_into_admin_livewire_and_installer_are_refused(): void
    {
        // S2-R1 migration: the routes are owned by s2-alpha and declared, so the
        // reserved-path rule (not a missing declaration) is what refuses admin/Livewire.
        $alpha = $this->pluginCode('s2-alpha');
        $this->promoteRoute($alpha->route(['GET'], '/admin/s2-probe', 's2-alpha.admin', fn () => 'admin'));
        $this->promoteRoute($alpha->route(['GET'], '/s2-allowed', 's2-alpha.allowed', fn () => 'ok'));
        $alpha->route(['POST'], '/livewire/update', 's2-alpha.wire', fn () => 'wire');
        $targets = [['s2-alpha.admin', ['GET']], ['s2-alpha.allowed', ['GET']], ['s2-alpha.wire', ['POST']]];
        $this->registerAlpha('admin', '<form method="get" action="/admin/s2-probe"></form>', ['public'], ['@host'], null, [], '', $targets);
        $this->registerAlpha('wire', '<form method="post" action="/livewire/update"></form>', ['public'], ['@host'], null, [], '', $targets);
        $this->registerAlpha('ok', '<form method="get" action="/s2-allowed"></form>', ['public'], ['@host'], null, [], '', $targets);

        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/admin'));
        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/wire'));
        $this->assertSame('<form method="get" action="/s2-allowed"></form>', $this->vaultHtml(cms_trusted_component('s2-alpha/ok')));
        $this->assertSame(['profile violation: form target: reserved path', 'profile violation: form target: reserved path'], $this->reasons());
    }

    public function test_nested_render_is_refused(): void
    {
        $this->registerAlpha('loop', '', ['public'], ['@host', 's2-alpha'], null, [], 'nested');

        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/loop'));
        $this->assertSame(['nested render', 'profile violation: marker'], $this->reasons());
    }

    public function test_slot_limit_per_request(): void
    {
        $this->registerAlpha('many', '<p>m</p>', ['public'], ['@host']);

        for ($i = 0; $i < TrustedSlotVault::MAX_SLOTS; $i++) {
            $this->assertNotSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/many'));
        }

        $this->assertSame(TrustedSlotVault::FALLBACK, cms_trusted_component('s2-alpha/many'));
        $this->assertSame(['slot limit'], $this->reasons());
    }

    public function test_diagnostics_never_contain_props_or_output(): void
    {
        $this->registerAlpha('xss', '<script>{prop:secret}</script>', ['public'], ['@host']);
        cms_trusted_component('s2-alpha/xss', ['secret' => 'PRIVATE-PROP-VALUE']);

        $json = json_encode($this->components()->diagnostics());
        $this->assertStringNotContainsString('PRIVATE-PROP-VALUE', $json);
        $this->assertStringNotContainsString('script', $json);
    }

    public function test_sequential_requests_in_one_container_share_providers_but_no_request_state(): void
    {
        $this->registerAlpha('seq', '<p>seq</p>', ['public'], ['@host']);

        $first = cms_trusted_component('s2-alpha/seq');
        cms_trusted_component('s2-alpha/missing');
        $this->assertNotEmpty($this->components()->diagnostics());

        $this->freshSlotRequest('/next');

        $this->assertSame([], $this->components()->diagnostics());
        $this->assertSame('', TrustedSlotVault::forRequest(request())->resolve($first, insert: true)['body']);
        $second = cms_trusted_component('s2-alpha/seq');
        $this->assertNotSame($first, $second);
        $this->assertSame('<p>seq</p>', $this->vaultHtml($second));
        $this->assertTrue($this->components()->has('s2-alpha/seq'));
    }
}
