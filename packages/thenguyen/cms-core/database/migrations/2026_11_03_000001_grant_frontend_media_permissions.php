<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-40-FRONTEND-MEDIA-R1 — data-only, additive, idempotent.
 *
 * Existing sites receive the two new permission rows, and every role that can ALREADY upload
 * media in the admin (`media.upload`) also receives `media.view_own` + `media.upload_own`.
 * The decision is by grant, never by role name, so an operator's custom roles keep their
 * meaning; a role without `media.upload` (e.g. `subscriber`) gets nothing. `admin.access` is
 * never granted. Fresh installs reach the same state through CmsRolePermissionSeeder.
 *
 * down() is intentionally a no-op: removing grants an operator may have relied on (or added by
 * hand after the upgrade) would be destructive. Revoke in Admin → Roles if needed.
 */
return new class extends Migration
{
    private const SLUGS = [
        'media.view_own' => 'View and select own media (frontend)',
        'media.upload_own' => 'Upload own media (frontend)',
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
                    'group' => 'Media',
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $ids[] = (int) $id;
        }

        $uploadId = DB::table('cms_permissions')->where('slug', 'media.upload')->value('id');
        if ($uploadId === null) {
            return;
        }

        $roleIds = DB::table('cms_role_permissions')->where('permission_id', $uploadId)->pluck('role_id');
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
