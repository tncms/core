<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-SITEMAP-ADMIN-R1 — data-only, additive, idempotent.
 *
 * Existing sites receive the four XML sitemap permission rows, and every role
 * that can ALREADY manage site settings (`settings.manage`) receives them —
 * the decision is by grant, never by role name, so custom roles keep their
 * meaning and editors/authors (no `settings.manage`) get nothing. Fresh
 * installs reach the same state through CmsRolePermissionSeeder.
 *
 * down() is intentionally a no-op: removing grants an operator may rely on
 * would be destructive. Revoke in Admin → Roles if needed.
 */
return new class extends Migration
{
    private const SLUGS = [
        'sitemap.view' => 'View XML sitemap settings and status',
        'sitemap.manage' => 'Manage XML sitemap settings',
        'sitemap.diagnostics' => 'Run XML sitemap diagnostics',
        'sitemap.cache_refresh' => 'Refresh the XML sitemap cache',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('cms_permissions') || ! Schema::hasTable('cms_roles') || ! Schema::hasTable('cms_role_permissions')) {
            return;
        }

        $now = now();
        $ids = [];
        foreach (self::SLUGS as $slug => $name) {
            $id = DB::table('cms_permissions')->where('slug', $slug)->value('id');
            if ($id === null) {
                $id = DB::table('cms_permissions')->insertGetId([
                    'slug' => $slug,
                    'name' => $name,
                    'group' => 'SEO',
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $ids[] = (int) $id;
        }

        $settingsId = DB::table('cms_permissions')->where('slug', 'settings.manage')->value('id');
        if ($settingsId === null) {
            return;
        }

        $roleIds = DB::table('cms_role_permissions')->where('permission_id', $settingsId)->pluck('role_id');
        foreach ($roleIds as $roleId) {
            foreach ($ids as $permissionId) {
                $exists = DB::table('cms_role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $exists) {
                    DB::table('cms_role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Intentionally left as is (see class docblock).
    }
};
