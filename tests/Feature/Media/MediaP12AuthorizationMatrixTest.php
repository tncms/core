<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;

/**
 * CORE-P12-1 — authorization matrix for the new media surfaces: guest, subscriber, author,
 * editor, admin, super-admin and plugin code (no actor). Granting media.delete_own or the lookup
 * scopes never implies admin.access, and no row here needs the Filament panel.
 */
final class MediaP12AuthorizationMatrixTest extends TestCase
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

    /** @return array<string, array{?string, bool, bool}> role => [slug, may delete own, may see own via lookupOwned] */
    public static function roles(): array
    {
        return [
            'guest' => [null, false, false],
            'subscriber' => ['subscriber', false, false],
            'author' => ['author', true, true],
            'editor' => ['editor', true, true],
            'admin' => ['admin', true, true],
            'super-admin' => ['super-admin', true, true],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix_for_owner_delete_and_owned_lookup(?string $role, bool $delete, bool $ownLookup): void
    {
        $user = $role !== null ? $this->userWithRole($role) : null;
        $own = $this->mediaRow($user);
        $foreign = $this->mediaRow($this->userWithRole('author'));
        if ($user !== null) {
            $this->actingAs($user);
        }

        $this->assertSame($delete, app('cms.media.frontend')->canDeleteOwn($user));
        $owned = app('cms.media.assets')->lookupOwned($user, [$own->id, $foreign->id]);
        $this->assertSame($ownLookup ? [$own->id] : [], array_keys($owned->found));
        $this->assertNotContains($foreign->id, array_keys($owned->found), 'never another owner\'s asset');

        $response = $this->deleteJson('/account/media/'.$foreign->id);
        $this->assertSame($user === null ? 401 : ($delete ? 404 : 403), $response->status());
        $this->assertNotSoftDeleted('cms_media', ['id' => $foreign->id]);

        // Public render scope needs no actor; it is the same for everyone.
        $this->assertSame([$own->id, $foreign->id], array_keys(app('cms.media.assets')->lookupPublic([$own->id, $foreign->id])->found));
    }

    public function test_plugin_code_without_actor_gets_public_scope_only(): void
    {
        $row = $this->mediaRow($this->userWithRole('author'));

        $this->assertSame([], app('cms.media.assets')->lookupOwned(null, [$row->id])->found);
        $this->assertSame([], app('cms.media.assets')->lookupManaged(null, [$row->id])->found);
        $this->assertSame([$row->id], array_keys(app('cms.media.assets')->lookupPublic([$row->id])->found));
    }

    public function test_delete_own_never_implies_admin_access(): void
    {
        $user = $this->userWithPermissions(['media.view_own', 'media.upload_own', 'media.delete_own']);

        $this->assertTrue(app('cms.media.frontend')->canDeleteOwn($user));
        $this->assertFalse(app('cms.permission')->userCanStrict($user, 'admin.access'));
        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_registered_and_seeded(): void
    {
        $this->assertContains('media.delete_own', array_column(app('cms.permission')->allRegistered(), 'slug'));
        $this->assertDatabaseHas('cms_permissions', ['slug' => 'media.delete_own']);
    }
}
