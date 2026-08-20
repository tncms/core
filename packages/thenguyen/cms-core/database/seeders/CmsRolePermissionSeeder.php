<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Seeds the baseline roles + permissions (v1.0.0-beta.3). Idempotent.
 *
 * Behaviour:
 *   1. Mirror the registered permission catalogue into cms_permissions.
 *   2. Create/update the four core roles and their permission grants.
 *   3. On first initialisation only (no role↔user links yet), assign the
 *      super-admin role to the first existing user so the owner keeps full
 *      access. When no users exist, only roles/permissions are seeded.
 *
 * Re-running never overwrites a custom role assignment: once any user has a
 * role, the first-user bootstrap step is skipped.
 */
class CmsRolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('cms_roles') || ! Schema::hasTable('cms_permissions')) {
            return;
        }

        // Whether the RBAC system is being initialised for the first time.
        $freshInstall = ! DB::table('cms_role_user')->exists();

        // 1. Mirror the permission catalogue into the database.
        /** @var PermissionManager $permissions */
        $permissions = app('cms.permission');
        $permissions->syncDefaults();

        $allSlugs = Permission::query()->pluck('slug')->all();

        // 2. Create/update the core roles + their grants.
        $this->syncRole('super-admin', 'Super Admin', 'Full, unrestricted access.', true, $allSlugs);

        $this->syncRole('admin', 'Administrator', 'Manage the whole site except role/user removal.', true, array_values(array_filter(
            $allSlugs,
            static fn (string $slug): bool => ! in_array($slug, ['roles.manage', 'users.delete'], true),
        )));

        $this->syncRole('editor', 'Editor', 'Manage all content, media, taxonomy and menus.', false, [
            'admin.access',
            'content.view', 'content.create', 'content.edit', 'content.delete', 'content.publish',
            'pages.view', 'pages.create', 'pages.edit', 'pages.delete', 'pages.publish',
            'posts.view', 'posts.create', 'posts.edit', 'posts.delete', 'posts.publish',
            'taxonomy.manage',
            'media.view', 'media.upload', 'media.edit', 'media.delete',
            'menus.manage',
        ]);

        // Author: no post ownership scoping exists yet (documented limitation),
        // so authors can create/edit posts but not delete or publish them.
        $this->syncRole('author', 'Author', 'Write posts and upload media (no publish/delete).', false, [
            'admin.access',
            'posts.view', 'posts.create', 'posts.edit',
            'media.view', 'media.upload',
        ]);

        // Subscriber: the safe default role for frontend registration
        // (v1.0.0-beta.7.1.14). No permissions at all — notably no admin.access —
        // so a registered frontend user can never reach the admin panel.
        $this->syncRole('subscriber', 'Subscriber', 'Default frontend account with no admin access.', false, []);

        // 3. First-user bootstrap (only on fresh initialisation).
        if ($freshInstall) {
            $this->assignSuperAdminToFirstUser();
        }
    }

    /**
     * Create or update a role and replace its permission grants.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    private function syncRole(string $slug, string $name, string $description, bool $isSystem, array $permissionSlugs): void
    {
        $role = Role::query()->firstOrNew(['slug' => $slug]);
        $role->name = $name;
        $role->description = $description;
        $role->is_system = $isSystem;
        $role->save();

        $ids = Permission::query()
            ->whereIn('slug', $permissionSlugs)
            ->pluck('id')
            ->all();

        $role->permissions()->sync($ids);
    }

    private function assignSuperAdminToFirstUser(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $firstUserId = DB::table('users')->orderBy('id')->value('id');

        if ($firstUserId === null) {
            // No users yet — roles/permissions seeded, nothing to assign.
            return;
        }

        $superAdmin = Role::query()->where('slug', 'super-admin')->first();

        if ($superAdmin !== null) {
            $superAdmin->users()->syncWithoutDetaching([$firstUserId]);
        }
    }
}
