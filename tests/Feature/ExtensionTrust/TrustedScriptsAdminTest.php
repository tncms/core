<?php

declare(strict_types=1);

namespace Tests\Feature\ExtensionTrust;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\ExtensionTrust\Concerns\TrustedScriptFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Filament\Admin\Pages\TrustedScriptsPage;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Models\TrustedScriptApproval;
use TheNguyen\CMS\Models\TrustedScriptApprovalEvent;

/**
 * CORE-EXTENSION-TRUST S1 — Gate E: approval authority, persistence, audit and
 * the admin review UI.
 */
final class TrustedScriptsAdminTest extends TestCase
{
    use RefreshDatabase;
    use TrustedScriptFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->setCurrent('en');
        $this->useFixturePlugins();
        $this->cleanFixturePlugins();
        $this->writePlugin('ts-admin', ['turnstile' => $this->turnstileEntry()]);
        $this->activate('ts-admin');
    }

    private function superAdmin(): User
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $user = User::factory()->create();
        Role::query()->where('slug', 'super-admin')->firstOrFail()->users()->syncWithoutDetaching([$user->id]);

        return $user;
    }

    private function recordKey(): string
    {
        $declaration = $this->declarationFor('ts-admin', 'turnstile');

        return 'ts-admin/turnstile@'.$declaration->fingerprint;
    }

    // ---- Authority -----------------------------------------------------------

    public function test_permission_is_registered_and_not_granted_to_any_generic_role(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);

        $this->assertTrue(Permission::query()->where('slug', 'trusted_scripts.manage')->exists());

        foreach (['admin', 'editor', 'author', 'subscriber'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $this->assertFalse($role->permissions()->where('slug', 'trusted_scripts.manage')->exists(), $slug);
        }
    }

    public function test_administrator_role_cannot_approve(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $admin = User::factory()->create();
        Role::query()->where('slug', 'admin')->firstOrFail()->users()->syncWithoutDetaching([$admin->id]);

        // The admin role still manages plugins…
        $this->assertTrue(cms_can('plugins.activate', $admin));
        // …but approval authority is separate.
        $this->assertFalse(app('cms.permission')->userCanStrict($admin, 'trusted_scripts.manage'));

        $this->actingAs($admin);
        Livewire::test(TrustedScriptsPage::class)->assertForbidden();
    }

    public function test_roleless_user_is_forbidden(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $this->actingAs(User::factory()->create());

        Livewire::test(TrustedScriptsPage::class)->assertForbidden();
    }

    public function test_strict_check_never_fails_open_before_rbac_is_initialised(): void
    {
        $user = User::factory()->create();

        // Legacy userCan() fails open with no roles seeded…
        $this->assertTrue(cms_can('trusted_scripts.manage', $user));
        // …the approval authority does not.
        $this->assertFalse(app('cms.permission')->userCanStrict($user, 'trusted_scripts.manage'));
        $this->assertFalse(app('cms.permission')->userCanStrict(null, 'trusted_scripts.manage'));

        $this->actingAs($user);
        Livewire::test(TrustedScriptsPage::class)->assertForbidden();
    }

    public function test_configured_super_admin_email_is_allowed(): void
    {
        config(['cms.super_admin_emails' => ['owner@example.test']]);
        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->assertTrue(app('cms.permission')->userCanStrict($user, 'trusted_scripts.manage'));
    }

    public function test_dedicated_role_with_the_permission_is_allowed(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $role = Role::query()->create(['slug' => 'script-reviewer', 'name' => 'Script reviewer']);
        $role->permissions()->sync([Permission::query()->where('slug', 'trusted_scripts.manage')->value('id')]);
        $user = User::factory()->create();
        $role->users()->syncWithoutDetaching([$user->id]);

        $this->assertTrue(app('cms.permission')->userCanStrict($user, 'trusted_scripts.manage'));
    }

    // ---- UI + persistence ----------------------------------------------------

    public function test_super_admin_sees_exact_url_origins_purpose_and_pending_state(): void
    {
        $this->actingAs($this->superAdmin());
        $declaration = $this->declarationFor('ts-admin', 'turnstile');

        Livewire::test(TrustedScriptsPage::class)
            ->assertOk()
            ->assertSee(self::TURNSTILE)
            ->assertSee('script-src https://challenges.cloudflare.com')
            ->assertSee('frame-src https://challenges.cloudflare.com')
            ->assertSee('Cloudflare Turnstile challenge for contact forms')
            ->assertSee(substr($declaration->fingerprint, 0, 12))
            ->assertSee('Awaiting approval')
            ->assertSee('Third-party JavaScript');
    }

    public function test_approve_action_binds_fingerprint_actor_and_writes_audit(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);

        Livewire::test(TrustedScriptsPage::class)
            ->callTableAction('approve', $this->recordKey())
            ->assertHasNoTableActionErrors();

        $row = TrustedScriptApproval::query()->where('owner_slug', 'ts-admin')->firstOrFail();
        $this->assertSame('approved', $row->status);
        $this->assertSame($this->declarationFor('ts-admin', 'turnstile')->fingerprint, $row->fingerprint);
        $this->assertSame(self::TURNSTILE, $row->url);
        $this->assertSame($admin->id, $row->approved_by);
        $this->assertNotNull($row->approved_at);

        $event = TrustedScriptApprovalEvent::query()->firstOrFail();
        $this->assertSame(['ts-admin', 'turnstile', 'approved', $admin->id], [$event->owner_slug, $event->script_key, $event->action, $event->actor_id]);
    }

    public function test_approving_a_stale_fingerprint_is_impossible(): void
    {
        $this->actingAs($this->superAdmin());
        $staleKey = $this->recordKey();
        $component = Livewire::test(TrustedScriptsPage::class);

        // The plugin changes its declaration after the page was rendered.
        $this->writePlugin('ts-admin', ['turnstile' => $this->turnstileEntry(['csp' => [
            'script-src' => ['https://challenges.cloudflare.com'],
            'connect-src' => ['https://collector.example.com'],
        ]])]);
        $this->freshRequest();

        $component->callTableAction('approve', $staleKey);

        $this->assertFalse(TrustedScriptApproval::query()->exists());
        $this->assertFalse(TrustedScriptApprovalEvent::query()->exists());

        // The service-level guard refuses too.
        $page = new TrustedScriptsPage;
        $page->approveRecord(['owner' => 'ts-admin', 'key' => 'turnstile', 'fingerprint' => str_repeat('0', 64)]);
        $this->assertFalse(TrustedScriptApproval::query()->exists());
    }

    public function test_changed_declaration_shows_reapproval_state(): void
    {
        $this->actingAs($this->superAdmin());
        $this->approve('ts-admin', 'turnstile');
        $this->writePlugin('ts-admin', ['turnstile' => $this->turnstileEntry(['attributes' => ['async' => true]])]);
        $this->freshRequest();

        Livewire::test(TrustedScriptsPage::class)->assertSee('Re-approval required');
    }

    public function test_revoke_action_revokes_and_audits(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->approve('ts-admin', 'turnstile', $admin->id);

        Livewire::test(TrustedScriptsPage::class)
            ->callTableAction('revoke', $this->recordKey())
            ->assertHasNoTableActionErrors();

        $row = TrustedScriptApproval::query()->firstOrFail();
        $this->assertSame('revoked', $row->status);
        $this->assertSame($admin->id, $row->revoked_by);
        $this->assertSame('admin', $row->revocation_reason);
        $this->assertSame(['approved', 'revoked'], TrustedScriptApprovalEvent::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_the_row_shows_the_new_state_right_after_approve(): void
    {
        $this->actingAs($this->superAdmin());

        // Release .35 browser finding: the table kept rendering "Awaiting approval"
        // until a reload, because the records were cached before the action ran.
        Livewire::test(TrustedScriptsPage::class)
            ->assertSee('Awaiting approval')
            ->callTableAction('approve', $this->recordKey())
            ->assertHasNoTableActionErrors()
            ->assertSee('Approved')
            ->assertDontSee('Awaiting approval')
            ->assertTableActionVisible('revoke', $this->recordKey())
            ->assertTableActionHidden('approve', $this->recordKey());
    }

    public function test_the_row_shows_the_new_state_right_after_revoke(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin);
        $this->approve('ts-admin', 'turnstile', $admin->id);

        Livewire::test(TrustedScriptsPage::class)
            ->assertTableActionVisible('revoke', $this->recordKey())
            ->callTableAction('revoke', $this->recordKey())
            ->assertHasNoTableActionErrors()
            ->assertSee('Revoked')
            ->assertTableActionVisible('approve', $this->recordKey())
            ->assertTableActionHidden('revoke', $this->recordKey());
    }

    public function test_unauthorized_direct_method_call_is_refused(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $this->actingAs(User::factory()->create());
        $declaration = $this->declarationFor('ts-admin', 'turnstile');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        (new TrustedScriptsPage)->approveRecord(['owner' => 'ts-admin', 'key' => 'turnstile', 'fingerprint' => $declaration->fingerprint]);
    }

    public function test_rejected_declarations_are_listed(): void
    {
        $this->writePlugin('ts-admin-bad', ['evil' => $this->turnstileEntry(['csp' => ['script-src' => ["'unsafe-inline'"]]])]);
        $this->activate('ts-admin-bad');
        $this->actingAs($this->superAdmin());

        Livewire::test(TrustedScriptsPage::class)
            ->assertSee('Rejected declarations')
            ->assertSee('ts-admin-bad / evil')
            ->assertSee('invalid csp');
    }

    // ---- Migrations ----------------------------------------------------------

    public function test_migrations_are_additive_reversible_and_idempotent(): void
    {
        $dir = base_path('packages/thenguyen/cms-core/database/migrations/');
        $approvals = require $dir.'2026_10_24_000001_create_cms_trusted_script_approvals_table.php';
        $events = require $dir.'2026_10_24_000002_create_cms_trusted_script_approval_events_table.php';

        $this->assertTrue(Schema::hasTable('cms_trusted_script_approvals'));
        $this->assertTrue(Schema::hasTable('cms_trusted_script_approval_events'));

        $approvals->up();
        $events->up();

        $events->down();
        $approvals->down();
        $this->assertFalse(Schema::hasTable('cms_trusted_script_approvals'));
        $this->assertFalse(Schema::hasTable('cms_trusted_script_approval_events'));

        // With the tables gone, reads fail closed instead of throwing.
        $this->freshRequest();
        $this->assertFalse(app('cms.trusted_script_approvals')->isApproved($this->declarationFor('ts-admin', 'turnstile')));

        $approvals->up();
        $events->up();
        $this->assertTrue(Schema::hasColumns('cms_trusted_script_approvals', ['owner_slug', 'script_key', 'fingerprint', 'url', 'status', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at', 'revocation_reason']));
    }
}
