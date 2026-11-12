<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-P12-1 — data-only, additive, idempotent.
 *
 * Adds the `media.delete_own` permission and grants it to every role that can already upload its
 * own frontend media (`media.upload_own`): whoever may fill their quota may also free it. The
 * decision is by grant, never by role name; `admin.access` is never granted. Fresh installs reach
 * the same state through CmsRolePermissionSeeder. Unique keys on cms_permissions.slug and
 * (role_id, permission_id) make a concurrent duplicate fail instead of corrupting data, and
 * insertOrIgnore keeps a re-run or a racing migrator harmless.
 *
 * down() is intentionally a no-op: removing a grant an operator may rely on would be destructive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_permissions') || ! Schema::hasTable('cms_roles') || ! Schema::hasTable('cms_role_permissions')) {
            return;
        }

        $now = now();
        DB::table('cms_permissions')->insertOrIgnore([
            'slug' => 'media.delete_own',
            'name' => 'Delete own media (frontend)',
            'group' => 'Media',
            'description' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $deleteId = DB::table('cms_permissions')->where('slug', 'media.delete_own')->value('id');
        $uploadOwnId = DB::table('cms_permissions')->where('slug', 'media.upload_own')->value('id');
        if ($deleteId === null || $uploadOwnId === null) {
            return;
        }

        $roleIds = DB::table('cms_role_permissions')->where('permission_id', $uploadOwnId)->pluck('role_id');
        foreach ($roleIds as $roleId) {
            DB::table('cms_role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $deleteId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally left as is (see class docblock).
    }
};
