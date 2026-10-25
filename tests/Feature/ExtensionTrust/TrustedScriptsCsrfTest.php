<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Feature\ExtensionTrust\Concerns\TrustedScriptFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\TrustedScriptApproval;
use TheNguyen\CMS\Models\TrustedScriptApprovalEvent;

/**
 * CORE-EXTENSION-TRUST S1-R1 — Gate B: CSRF + RBAC for approve/revoke over the
 * REAL transport.
 *
 * The Trusted Scripts actions are Filament table actions on a Livewire page, so
 * the only state-changing endpoint is Livewire's `POST /livewire-{hash}/update`
 * (middleware `web` + RequireLivewireHeaders; Filament's auth is applied as
 * Livewire persistent middleware). Each request goes through the full HTTP
 * kernel with a snapshot obtained from a real GET of the admin page.
 *
 * Laravel's ValidateCsrfToken skips verification while running unit tests.
 * These tests bind a subclass that removes ONLY that bypass, so the framework's
 * own token comparison (`_token` / X-CSRF-TOKEN / X-XSRF-TOKEN against the
 * session) is exercised exactly as in production. A control test proves the
 * bypass would otherwise hide CSRF failures.
 */
final class TrustedScriptsCsrfTest extends TestCase
{
    use RefreshDatabase;
    use TrustedScriptFixtures;

    private const PAGE = '/admin/plugins/trusted-scripts';

    private const COMPONENT = 'TheNguyen\\CMS\\Filament\\Admin\\Pages\\TrustedScriptsPage';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enforceCsrf();
        $this->withoutVite();

        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->setCurrent('en');
        $this->useFixturePlugins();
        $this->cleanFixturePlugins();
        $this->writePlugin('ts-csrf', ['turnstile' => $this->turnstileEntry()]);
        $this->activate('ts-csrf');
        $this->seed(CmsRolePermissionSeeder::class);
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            /** Production behaviour: never skip verification because PHPUnit is running. */
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        Role::query()->where('slug', $slug)->firstOrFail()->users()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    private function recordKey(): string
    {
        return 'ts-csrf/turnstile@'.$this->declarationFor('ts-csrf', 'turnstile')->fingerprint;
    }

    /** GET the page as the current user; return the page component snapshot + this session's token. */
    private function openPage(): array
    {
        $response = $this->get(self::PAGE);
        $response->assertOk();

        preg_match_all('/wire:snapshot="([^"]+)"/', (string) $response->getContent(), $matches);

        foreach ($matches[1] as $raw) {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === self::COMPONENT) {
                return [$snapshot, (string) session()->token()];
            }
        }

        $this->fail('TrustedScriptsPage snapshot not found on the page.');
    }

    /** One Livewire update request through the full kernel. */
    private function livewire(string $snapshot, array $calls, ?string $token, array $headers = []): TestResponse
    {
        $body = ['components' => [['snapshot' => $snapshot, 'updates' => new \stdClass, 'calls' => $calls]]];

        if ($token !== null) {
            $body['_token'] = $token;
        }

        return $this->postJson(EndpointResolver::updatePath(), $body, ['X-Livewire' => '1'] + $headers);
    }

    private function mountCall(string $action): array
    {
        return [['path' => '', 'method' => 'mountAction', 'params' => [$action, [], ['table' => true, 'recordKey' => $this->recordKey()]]]];
    }

    private function confirmCall(): array
    {
        return [['path' => '', 'method' => 'callMountedAction', 'params' => []]];
    }

    /** Full UI flow: open modal, confirm. Returns the confirm response. */
    private function runAction(string $action, string $snapshot, ?string $token, array $headers = []): TestResponse
    {
        $mount = $this->livewire($snapshot, $this->mountCall($action), $token, $headers);

        if ($mount->getStatusCode() !== 200) {
            return $mount;
        }

        $next = (string) $mount->json('components.0.snapshot');

        return $this->livewire($next, $this->confirmCall(), $token, $headers);
    }

    private function approvalState(): array
    {
        return [
            TrustedScriptApproval::query()->get(['status', 'fingerprint'])->toArray(),
            TrustedScriptApprovalEvent::query()->count(),
        ];
    }

    // ---- Control --------------------------------------------------------------

    public function test_control_the_framework_test_bypass_is_what_these_tests_remove(): void
    {
        $this->assertInstanceOf(ValidateCsrfToken::class, app(ValidateCsrfToken::class));
        $this->assertNotSame(ValidateCsrfToken::class, app(ValidateCsrfToken::class)::class, 'strict subclass bound');

        $this->app->offsetUnset(ValidateCsrfToken::class);
        $this->app->forgetInstance(ValidateCsrfToken::class);
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot] = $this->openPage();

        // Default test environment: CSRF skipped, so a token-less request is NOT rejected.
        $this->assertNotSame(419, $this->livewire($snapshot, [], null)->getStatusCode());
    }

    // ---- Positive controls -----------------------------------------------------

    public function test_positive_super_admin_with_valid_token_approves_and_revokes_through_the_ui_transport(): void
    {
        $admin = $this->userWithRole('super-admin');
        $this->actingAs($admin);
        [$snapshot, $token] = $this->openPage();

        $this->runAction('approve', $snapshot, $token)->assertOk();

        $row = TrustedScriptApproval::query()->firstOrFail();
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->declarationFor('ts-csrf', 'turnstile')->fingerprint, $row->fingerprint);
        $this->assertSame($admin->id, $row->approved_by);

        [$snapshot, $token] = $this->openPage();
        $this->runAction('revoke', $snapshot, $token)->assertOk();

        $this->assertSame('revoked', TrustedScriptApproval::query()->firstOrFail()->status);
        $this->assertSame(['approved', 'revoked'], TrustedScriptApprovalEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_positive_token_in_header_is_accepted(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot, $token] = $this->openPage();

        $this->runAction('approve', $snapshot, null, ['X-CSRF-TOKEN' => $token])->assertOk();

        $this->assertSame('approved', TrustedScriptApproval::query()->firstOrFail()->status);
    }

    public function test_positive_administrator_explicitly_granted_the_permission_can_approve(): void
    {
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([Permission::query()->where('slug', 'trusted_scripts.manage')->value('id')]);
        $this->actingAs($this->userWithRole('admin'));
        [$snapshot, $token] = $this->openPage();

        $this->runAction('approve', $snapshot, $token)->assertOk();

        $this->assertSame('approved', TrustedScriptApproval::query()->firstOrFail()->status);
    }

    // ---- CSRF negatives (approve + revoke) -------------------------------------

    /** @return array<string, array{?string, array<string, string>}> */
    private function badTokens(string $valid): array
    {
        return [
            'missing token' => [null, []],
            'empty token' => ['', []],
            'malformed token' => ['not-a-csrf-token', []],
            'truncated valid token' => [substr($valid, 0, 20), []],
            'valid token wrong case' => [strtoupper($valid), []],
            'forged X-CSRF-TOKEN header' => [null, ['X-CSRF-TOKEN' => 'forged']],
            'forged X-XSRF-TOKEN header' => [null, ['X-XSRF-TOKEN' => 'eyJpdiI6ImZvcmdlZCJ9']],
        ];
    }

    public function test_approve_without_a_valid_token_is_419_and_mutates_nothing(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot, $token] = $this->openPage();
        $before = $this->approvalState();

        foreach ($this->badTokens($token) as $case => [$bad, $headers]) {
            $mount = $this->livewire($snapshot, $this->mountCall('approve'), $bad, $headers);
            $this->assertSame(419, $mount->getStatusCode(), "mount: {$case}");

            $direct = $this->livewire($snapshot, [
                ['path' => '', 'method' => 'mountAction', 'params' => ['approve', [], ['table' => true, 'recordKey' => $this->recordKey()]]],
                ['path' => '', 'method' => 'callMountedAction', 'params' => []],
            ], $bad, $headers);
            $this->assertSame(419, $direct->getStatusCode(), "mount+confirm in one request: {$case}");
        }

        $this->assertSame($before, $this->approvalState());
        $this->assertFalse(TrustedScriptApproval::query()->exists());
    }

    public function test_revoke_without_a_valid_token_is_419_and_mutates_nothing(): void
    {
        $admin = $this->userWithRole('super-admin');
        $this->approve('ts-csrf', 'turnstile', $admin->id);
        $this->actingAs($admin);
        [$snapshot, $token] = $this->openPage();
        $before = $this->approvalState();

        foreach ($this->badTokens($token) as $case => [$bad, $headers]) {
            $this->assertSame(419, $this->livewire($snapshot, $this->mountCall('revoke'), $bad, $headers)->getStatusCode(), "mount: {$case}");
        }

        // Valid modal open, then a forged confirm request.
        $mounted = $this->livewire($snapshot, $this->mountCall('revoke'), $token);
        $mounted->assertOk();
        $this->assertSame(419, $this->livewire((string) $mounted->json('components.0.snapshot'), $this->confirmCall(), 'forged')->getStatusCode());

        $this->assertSame($before, $this->approvalState());
        $this->assertSame('approved', TrustedScriptApproval::query()->firstOrFail()->status);
        $this->assertSame(['approved'], TrustedScriptApprovalEvent::query()->pluck('action')->all());
    }

    public function test_cross_session_token_is_rejected(): void
    {
        // Session A (attacker's own valid session) supplies a token...
        $this->actingAs($this->userWithRole('super-admin'));
        [, $tokenA] = $this->openPage();

        // ...session B (the victim) is a fresh session with its own token.
        $this->app['session.store']->invalidate();
        $victim = $this->userWithRole('super-admin');
        $this->actingAs($victim);
        [$snapshotB, $tokenB] = $this->openPage();
        $this->assertNotSame($tokenA, $tokenB);

        $this->assertSame(419, $this->runAction('approve', $snapshotB, $tokenA)->getStatusCode());
        $this->assertFalse(TrustedScriptApproval::query()->exists());
        $this->assertSame(0, TrustedScriptApprovalEvent::query()->count());
    }

    public function test_replay_after_logout_is_rejected(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot, $token] = $this->openPage();

        // Logout invalidates the session and regenerates its token.
        $this->post('/admin/logout', ['_token' => $token]);
        $this->assertNotSame($token, session()->token());

        $this->assertSame(419, $this->runAction('approve', $snapshot, $token)->getStatusCode());
        $this->assertFalse(TrustedScriptApproval::query()->exists());
    }

    // ---- RBAC over the real transport ------------------------------------------

    public function test_administrator_cannot_open_the_page(): void
    {
        $this->actingAs($this->userWithRole('admin'));

        $this->get(self::PAGE)->assertForbidden();
    }

    public function test_administrator_replaying_a_super_admin_snapshot_with_a_valid_own_token_cannot_mutate_or_read(): void
    {
        // A snapshot is HMAC-signed with the app key, not bound to a session, so
        // it can be replayed by another authenticated user.
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot] = $this->openPage();

        $this->app['session.store']->invalidate();
        $this->actingAs($this->userWithRole('admin'));
        $this->get('/admin')->assertOk();
        $token = (string) session()->token();

        foreach (['approve', 'revoke'] as $action) {
            $response = $this->runAction($action, $snapshot, $token);
            $this->assertSame(403, $response->getStatusCode(), "{$action} via UI flow");
        }

        foreach ([
            ['approveRecord', [['owner' => 'ts-csrf', 'key' => 'turnstile', 'fingerprint' => $this->declarationFor('ts-csrf', 'turnstile')->fingerprint]]],
            ['revokeRecord', [['owner' => 'ts-csrf', 'key' => 'turnstile']]],
            ['rows', []],
            ['invalidDeclarations', []],
        ] as [$method, $params]) {
            $response = $this->livewire($snapshot, [['path' => '', 'method' => $method, 'params' => $params]], $token);
            $this->assertSame(403, $response->getStatusCode(), "direct {$method}()");
            $this->assertStringNotContainsString('challenges.cloudflare.com', (string) $response->getContent(), "{$method}() must not leak declarations");
        }

        $this->assertFalse(TrustedScriptApproval::query()->exists());
        $this->assertSame(0, TrustedScriptApprovalEvent::query()->count());
    }

    public function test_guest_cannot_reach_the_actions(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot] = $this->openPage();

        $this->app['session.store']->invalidate();
        $this->app['auth']->guard('web')->logout();
        $this->get('/admin/login');
        $token = (string) session()->token();

        $status = $this->runAction('approve', $snapshot, $token)->getStatusCode();
        // Valid CSRF token for the guest's own session, so the request reaches
        // Filament's persistent Authenticate middleware, which answers 401.
        $this->assertSame(401, $status);
        $this->assertFalse(TrustedScriptApproval::query()->exists());
    }

    // ---- Invariants still hold through the transport ---------------------------

    public function test_stale_fingerprint_record_cannot_be_approved_through_the_transport(): void
    {
        $this->actingAs($this->userWithRole('super-admin'));
        [$snapshot, $token] = $this->openPage();
        $staleKey = $this->recordKey();

        $this->writePlugin('ts-csrf', ['turnstile' => $this->turnstileEntry(['attributes' => ['async' => true]])]);

        $mount = $this->livewire($snapshot, [['path' => '', 'method' => 'mountAction', 'params' => ['approve', [], ['table' => true, 'recordKey' => $staleKey]]]], $token);
        if ($mount->getStatusCode() === 200) {
            $this->livewire((string) $mount->json('components.0.snapshot'), $this->confirmCall(), $token);
        }

        $this->assertFalse(TrustedScriptApproval::query()->exists());
    }
}
