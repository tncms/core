<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;

/**
 * CORE-P12-1 — the upgrade migration grants `media.delete_own` exactly where `media.upload_own`
 * is granted, is idempotent, never grants admin.access, and down() keeps operator data.
 */
final class MediaDeleteOwnPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require base_path('packages/thenguyen/cms-core/database/migrations/2026_11_05_000002_grant_media_delete_own_permission.php');
    }

    private function grantedRoles(string $slug): array
    {
        return DB::table('cms_role_permissions')
            ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_role_permissions.permission_id')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_permissions.role_id')
            ->where('cms_permissions.slug', $slug)->orderBy('cms_roles.slug')->pluck('cms_roles.slug')->all();
    }

    public function test_upgrade_shape_grants_delete_own_to_upload_own_holders_only_and_is_idempotent(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        // Simulate a .39 database: the permission did not exist yet.
        $id = DB::table('cms_permissions')->where('slug', 'media.delete_own')->value('id');
        DB::table('cms_role_permissions')->where('permission_id', $id)->delete();
        DB::table('cms_permissions')->where('id', $id)->delete();
        // An operator-created role that can upload its own media.
        $custom = DB::table('cms_roles')->insertGetId(['slug' => 'contributor', 'name' => 'Contributor', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cms_role_permissions')->insert([
            'role_id' => $custom,
            'permission_id' => DB::table('cms_permissions')->where('slug', 'media.upload_own')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $adminAccessBefore = $this->grantedRoles('admin.access');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($this->grantedRoles('media.upload_own'), $this->grantedRoles('media.delete_own'));
        $this->assertContains('contributor', $this->grantedRoles('media.delete_own'));
        $this->assertNotContains('subscriber', $this->grantedRoles('media.delete_own'));
        $this->assertSame(1, DB::table('cms_permissions')->where('slug', 'media.delete_own')->count());
        $this->assertSame($adminAccessBefore, $this->grantedRoles('admin.access'));

        $this->migration()->down();
        $this->assertSame(1, DB::table('cms_permissions')->where('slug', 'media.delete_own')->count());
    }

    public function test_fresh_seed_matches_the_migrated_state(): void
    {
        $this->seed(CmsRolePermissionSeeder::class);
        $before = $this->grantedRoles('media.delete_own');

        $this->migration()->up();

        $this->assertSame($before, $this->grantedRoles('media.delete_own'));
        $this->assertSame($this->grantedRoles('media.upload_own'), $before);
    }
}
