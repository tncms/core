<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\Frontend\FrontendMediaException;
use TheNguyen\CMS\Media\Frontend\FrontendMediaQuery;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-40-FRONTEND-MEDIA-R1 Gate T2 — permission matrix for media.view_own / media.upload_own.
 */
final class FrontendMediaPermissionMatrixTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    public function test_permissions_are_registered_and_seeded(): void
    {
        $registered = array_column(app('cms.permission')->allRegistered(), 'slug');
        $this->assertContains('media.view_own', $registered);
        $this->assertContains('media.upload_own', $registered);
        $this->assertTrue(Permission::query()->where('slug', 'media.view_own')->exists());
        $this->assertTrue(Permission::query()->where('slug', 'media.upload_own')->exists());
    }

    /** @return array<string, array{string, bool}> */
    public static function defaultGrants(): array
    {
        return [
            'super-admin' => ['super-admin', true],
            'admin' => ['admin', true],
            'editor' => ['editor', true],
            'author' => ['author', true],
            'subscriber' => ['subscriber', false],
        ];
    }

    #[DataProvider('defaultGrants')]
    public function test_default_role_matrix(string $role, bool $granted): void
    {
        $user = $this->userWithRole($role);

        $this->assertSame($granted, app('cms.media.frontend')->canViewOwn($user));
        $this->assertSame($granted, app('cms.media.frontend')->canUploadOwn($user));
        $this->getJson('/account/media')->assertUnauthorized();
        $this->actingAs($user)->getJson('/account/media')->assertStatus($granted ? 200 : 403);
    }

    public function test_default_roles_receive_no_new_admin_access(): void
    {
        $subscriber = Role::query()->where('slug', 'subscriber')->firstOrFail();
        $this->assertFalse(app('cms.permission')->roleHas($subscriber, 'admin.access'));
        $this->assertFalse(app('cms.permission')->roleHas($subscriber, 'media.view_own'));
    }

    public function test_a_custom_role_with_only_own_media_permissions_works_without_admin_access(): void
    {
        $user = $this->userWithPermissions(['media.view_own', 'media.upload_own']);

        $this->assertFalse(cms_can('admin.access', $user));
        $this->actingAs($user)->getJson('/account/media')->assertOk();
        $this->actingAs($user)->post('/account/media', ['file' => $this->realImage('a.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_view_own_without_upload_own_can_list_but_not_upload(): void
    {
        $user = $this->userWithPermissions(['media.view_own']);

        $this->actingAs($user)->getJson('/account/media')->assertOk();
        $this->actingAs($user)->post('/account/media', ['file' => $this->realImage('a.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('error.code', 'media_permission_denied');
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_upload_own_without_view_own_can_upload_but_not_list(): void
    {
        $user = $this->userWithPermissions(['media.upload_own']);

        $this->actingAs($user)->post('/account/media', ['file' => $this->realImage('a.jpg')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($user)->getJson('/account/media')->assertForbidden();
    }

    public function test_admin_media_permissions_do_not_imply_own_media_permissions(): void
    {
        $user = $this->userWithPermissions(['admin.access', 'media.view', 'media.upload', 'media.edit', 'media.delete']);

        $this->assertFalse(app('cms.media.frontend')->canViewOwn($user));
        $this->assertFalse(app('cms.media.frontend')->canUploadOwn($user));
    }

    public function test_revocation_takes_effect_on_the_next_request(): void
    {
        $user = $this->userWithPermissions(['media.view_own', 'media.upload_own'], 'revocable');
        $this->actingAs($user)->getJson('/account/media')->assertOk();

        $role = Role::query()->where('slug', 'revocable')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'media.view_own')->value('id'));
        $this->flushPermissions(); // next request

        $this->actingAs($user)->getJson('/account/media')->assertForbidden();
        $this->assertFalse(app('cms.media.frontend')->canViewOwn($user));
    }

    public function test_service_rechecks_permission_on_every_call_within_a_long_lived_component(): void
    {
        // A Livewire component calls the service on each request; the service itself is the gate.
        $user = $this->userWithPermissions(['media.view_own'], 'lw');
        $this->assertSame([], app('cms.media.frontend')->listOwn($user, new FrontendMediaQuery)->items);

        DB::table('cms_role_user')->where('user_id', $user->id)->delete();
        $this->flushPermissions();

        $this->expectException(FrontendMediaException::class);
        app('cms.media.frontend')->listOwn($user, new FrontendMediaQuery);
    }

    public function test_frontend_media_never_fails_open_before_rbac_is_initialised(): void
    {
        DB::table('cms_role_user')->delete();
        DB::table('cms_role_permissions')->delete();
        DB::table('cms_roles')->delete();
        $this->flushPermissions();
        $user = User::factory()->create();

        $this->assertTrue(cms_can('media.upload', $user), 'precondition: legacy checks fail open');
        $this->assertFalse(app('cms.media.frontend')->canUploadOwn($user));
        $this->actingAs($user)->getJson('/account/media')->assertForbidden();
    }

    public function test_cross_owner_listing_and_resolution_are_denied(): void
    {
        $alice = $this->userWithRole('author');
        $bob = $this->userWithRole('author');
        $aliceMedia = $this->mediaRow($alice);

        $listed = $this->actingAs($bob)->getJson('/account/media')->assertOk()->json('data');
        $this->assertSame([], $listed);
        $this->assertNull(app('cms.media.frontend')->resolveForActor($bob, $aliceMedia->id));
    }

    public function test_a_client_supplied_owner_or_privilege_flag_is_ignored(): void
    {
        $alice = $this->userWithRole('author');
        $bob = $this->userWithRole('author');
        $this->mediaRow($alice);

        $this->actingAs($bob)->getJson('/account/media?uploaded_by='.$alice->id.'&owner='.$alice->id.'&privileged=1&all=1')
            ->assertOk()->assertJsonCount(0, 'data');

        $id = $this->actingAs($bob)->post('/account/media', ['file' => $this->realImage('b.jpg'), 'uploaded_by' => $alice->id], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->assertSame($bob->id, (int) \TheNguyen\CMS\Models\Media::query()->findOrFail($id)->uploaded_by);
    }
}
