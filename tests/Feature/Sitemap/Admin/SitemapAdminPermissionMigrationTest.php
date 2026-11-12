<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sitemap\Admin\Concerns\SitemapAdminFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-SITEMAP-ADMIN-R1 — upgrade path: an existing site gains the sitemap
 * permissions, granted by existing `settings.manage` grants (never by role
 * name), idempotently and without touching other grants.
 */
final class SitemapAdminPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SitemapAdminFixtures;

    private const SLUGS = ['sitemap.view', 'sitemap.manage', 'sitemap.diagnostics', 'sitemap.cache_refresh'];

    private function migration(): object
    {
        return require base_path('packages/thenguyen/cms-core/database/migrations/2026_11_04_000001_grant_sitemap_admin_permissions.php');
    }

    public function test_guests_are_sent_to_the_admin_login_over_http(): void
    {
        $this->get('/admin/xml-sitemap')->assertRedirect();
        $this->actingAs($this->userWithRole('editor'));
        $this->get('/admin/xml-sitemap')->assertForbidden();
        $this->actingAs($this->userWithRole('admin'));
        $this->get('/admin/xml-sitemap')->assertOk()->assertSee(tn_trans('XML Sitemap'));
    }

    public function test_an_upgraded_site_grants_by_existing_settings_authority(): void
    {
        $this->seedRbac();
        // Simulate a .39 site: the rows do not exist yet.
        $ids = Permission::query()->whereIn('slug', self::SLUGS)->pluck('id');
        DB::table('cms_role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::query()->whereIn('slug', self::SLUGS)->delete();

        $custom = Role::query()->create(['slug' => 'seo-team', 'name' => 'SEO team', 'is_system' => false]);
        $custom->permissions()->sync(Permission::query()->whereIn('slug', ['admin.access', 'settings.manage'])->pluck('id')->all());
        $before = DB::table('cms_role_permissions')->count();

        $this->migration()->up();
        $this->migration()->up();

        foreach (self::SLUGS as $slug) {
            $this->assertTrue(Permission::query()->where('slug', $slug)->exists(), $slug);
            foreach (['admin', 'seo-team'] as $role) {
                $this->assertTrue(Role::query()->where('slug', $role)->firstOrFail()->permissions()->where('slug', $slug)->exists(), "{$role} {$slug}");
            }
            foreach (['editor', 'author', 'subscriber'] as $role) {
                $this->assertFalse(Role::query()->where('slug', $role)->firstOrFail()->permissions()->where('slug', $slug)->exists(), "{$role} {$slug}");
            }
        }

        $holders = DB::table('cms_role_permissions')
            ->where('permission_id', Permission::query()->where('slug', 'settings.manage')->value('id'))
            ->count();
        $this->assertSame($before + 4 * $holders, DB::table('cms_role_permissions')->count(), 'only the new grants, once');
    }
}
