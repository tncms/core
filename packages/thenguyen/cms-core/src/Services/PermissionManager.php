<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * Permission registry + authorization resolver (v1.0.0-beta.3).
 *
 * Holds the canonical list of permission slugs (declared in code, registered in
 * the constructor and extensible at runtime by plugins) and answers "can this
 * user do X?". The rules live here and nowhere else:
 *
 *   1. **Fail-open until initialised.** While no roles exist yet (fresh install,
 *      pre-seed), every check passes — so the existing Filament login and admin
 *      keep working exactly as before and nobody is ever locked out.
 *   2. **Super-admin bypass.** A user with the "super-admin" role, or whose email
 *      is listed in config('cms.super_admin_emails'), is granted everything.
 *   3. Otherwise the user is granted a permission only if one of their roles has
 *      it in cms_role_permissions.
 *
 * Resolution is memoised per user for the request (the manager is a singleton),
 * and every database touch is guarded so a missing table can never 500.
 */
class PermissionManager
{
    /**
     * Registered permissions, keyed by slug.
     *
     * @var array<string, array{slug: string, name: string, group: ?string, description: ?string}>
     */
    private array $registered = [];

    /**
     * Per-request resolution cache, keyed by user id:
     * ['super' => bool, 'slugs' => array<int, string>].
     *
     * @var array<int, array{super: bool, slugs: array<int, string>}>
     */
    private array $resolveCache = [];

    private ?bool $initialized = null;

    public const CORE_OWNER = 'core';

    /** @var array<string, string> slug => owner */
    private array $owners = [];

    /** @var list<array{slug: string, owner: string, reason: string}> */
    private array $diagnostics = [];

    private bool $registeringCore = false;

    public function __construct()
    {
        $this->registeringCore = true;

        try {
            $this->registerCorePermissions();
        } finally {
            $this->registeringCore = false;
        }
    }

    // ---------------------------------------------------------------------
    // Registry
    // ---------------------------------------------------------------------

    /**
     * Register (or overwrite) a permission in the in-memory registry. Plugins
     * call this from their service provider to add permissions; nothing is
     * persisted until syncDefaults() runs.
     */
    public function register(string $slug, string $name, ?string $group = null, ?string $description = null): void
    {
        if ($slug === '') {
            return;
        }

        // Definition ownership (CORE-STRUCTURED-CONTENT-AUTHORITY-R1): a slug
        // belongs to whoever registered it first (Core or a plugin, derived from
        // the calling file); another owner can never redefine it.
        $owner = $this->registeringCore ? self::CORE_OWNER : $this->callerOwner();
        $existing = $this->owners[$slug] ?? null;

        if ($existing !== null && $existing !== $owner) {
            $this->diagnostics[] = ['slug' => $slug, 'owner' => $owner, 'reason' => 'owned by '.$existing];

            return;
        }

        $this->owners[$slug] = $owner;
        $this->registered[$slug] = [
            'slug' => $slug,
            'name' => $name,
            'group' => $group,
            'description' => $description,
        ];
    }

    /** Owner of a registered slug: 'core', a plugin slug, or null when unregistered. */
    public function ownerOf(string $slug): ?string
    {
        return $this->owners[$slug] ?? null;
    }

    /** @return list<array{slug: string, owner: string, reason: string}> refused registrations */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    /**
     * Persist a subset of REGISTERED definitions into cms_permissions (idempotent
     * upsert by slug). A plugin caller can only persist slugs it owns; Core can
     * persist any. Never grants or removes a role permission, never deletes a row.
     *
     * @param  array<int, string>  $slugs
     * @return list<string> the slugs persisted
     */
    public function persist(array $slugs): array
    {
        if (! Schema::hasTable('cms_permissions')) {
            return [];
        }

        $caller = $this->callerOwner();
        $persisted = [];

        foreach (array_unique($slugs) as $slug) {
            $definition = $this->registered[$slug] ?? null;

            if ($definition === null || ($caller !== self::CORE_OWNER && ($this->owners[$slug] ?? null) !== $caller)) {
                continue;
            }

            Permission::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $definition['name'], 'group' => $definition['group'], 'description' => $definition['description']],
            );
            $persisted[] = $slug;
        }

        return $persisted;
    }

    /**
     * Persist every registered definition owned by one plugin.
     *
     * @return list<string>
     */
    public function persistOwnedBy(string $owner): array
    {
        return $this->persist(array_keys(array_filter($this->owners, static fn (string $o): bool => $o === $owner)));
    }

    /** Whether a slug has a cms_permissions row. */
    public function isPersisted(string $slug): bool
    {
        try {
            return Schema::hasTable('cms_permissions') && Permission::query()->where('slug', $slug)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** 'core' for host/Core code, the owning plugin slug for plugin code ('' when unowned). */
    private function callerOwner(): string
    {
        try {
            $identity = app('cms.extension_identity');

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $file = $frame['file'] ?? null;

                if (is_string($file) && $identity->isUnderPluginsRoot($file)) {
                    return $identity->ownerOfFile($file) ?? '';
                }
            }
        } catch (\Throwable) {
            // identity unavailable (isolated unit tests): treat as Core
        }

        return self::CORE_OWNER;
    }

    /**
     * All registered permissions, in registration order.
     *
     * @return array<int, array{slug: string, name: string, group: ?string, description: ?string}>
     */
    public function allRegistered(): array
    {
        return array_values($this->registered);
    }

    /**
     * Registered permissions bucketed by group (for the role editor UI).
     *
     * @return array<string, array<int, array{slug: string, name: string, group: ?string, description: ?string}>>
     */
    public function grouped(): array
    {
        $groups = [];

        foreach ($this->registered as $permission) {
            $group = $permission['group'] ?? 'General';
            $groups[$group][] = $permission;
        }

        return $groups;
    }

    /**
     * Mirror every registered permission into the cms_permissions table.
     * Idempotent (updateOrCreate by slug); never deletes unknown rows.
     */
    public function syncDefaults(): void
    {
        if (! Schema::hasTable('cms_permissions')) {
            return;
        }

        foreach ($this->registered as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                [
                    'name' => $permission['name'],
                    'group' => $permission['group'],
                    'description' => $permission['description'],
                ],
            );
        }
    }

    // ---------------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------------

    /**
     * Whether the user is granted the given permission slug.
     */
    public function userCan(?Authenticatable $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        // Fail-open until the RBAC system is initialised: keeps the existing
        // login/admin working before roles are ever seeded.
        if (! $this->systemInitialized()) {
            return true;
        }

        $resolved = $this->resolve($user);

        if ($resolved['super']) {
            return true;
        }

        return in_array($permission, $resolved['slugs'], true);
    }

    /**
     * Strict variant of {@see userCan()} for security-sensitive authority
     * (CORE-EXTENSION-TRUST S1): NEVER fail-open. Granted only to a super admin
     * or through an explicit role permission in an initialised RBAC system.
     */
    public function userCanStrict(?Authenticatable $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        $resolved = $this->resolve($user);

        if ($resolved['super']) {
            return true;
        }

        return $this->systemInitialized() && in_array($permission, $resolved['slugs'], true);
    }

    /**
     * Explicit-grant check for the most sensitive authority
     * (CORE-UNIFIED-OWNERSHIP-R1): true only when one of the user's roles
     * OTHER than super-admin carries the permission in an initialised RBAC
     * system. Super-admin status (role or configured email) never implies it,
     * so the grant is always a deliberate operator decision.
     */
    public function userHasExplicitGrant(?Authenticatable $user, string $permission): bool
    {
        $id = $user === null ? null : $this->userId($user);

        if ($id === null || ! $this->systemInitialized()) {
            return false;
        }

        try {
            return DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->join('cms_role_permissions', 'cms_role_permissions.role_id', '=', 'cms_roles.id')
                ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_role_permissions.permission_id')
                ->where('cms_role_user.user_id', $id)
                ->where('cms_roles.slug', '!=', Role::SUPER_ADMIN)
                ->where('cms_permissions.slug', $permission)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether the user is a super admin (super-admin role or configured email).
     */
    public function isSuperAdmin(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->resolve($user)['super'];
    }

    /**
     * Whether a specific role grants a permission (super-admin grants all).
     */
    public function roleHas(Role $role, string $permission): bool
    {
        if ($role->isSuperAdmin()) {
            return true;
        }

        return $role->permissions()->where('slug', $permission)->exists();
    }

    /**
     * Count of users holding the super-admin role — used by the UI to protect
     * the last super admin. Returns 0 safely when the tables are absent.
     */
    public function superAdminUserCount(): int
    {
        try {
            if (! Schema::hasTable('cms_role_user') || ! Schema::hasTable('cms_roles')) {
                return 0;
            }

            return (int) DB::table('cms_role_user')
                ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
                ->where('cms_roles.slug', Role::SUPER_ADMIN)
                ->distinct()
                ->count('cms_role_user.user_id');
        } catch (\Throwable) {
            return 0;
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Resolve and memoise a user's super flag + granted permission slugs.
     *
     * @return array{super: bool, slugs: array<int, string>}
     */
    private function resolve(Authenticatable $user): array
    {
        $super = $this->emailIsSuper($user);
        $id = $this->userId($user);

        if ($id === null) {
            return ['super' => $super, 'slugs' => []];
        }

        if (isset($this->resolveCache[$id])) {
            return $this->resolveCache[$id];
        }

        $slugs = [];

        try {
            $roleIds = DB::table('cms_role_user')->where('user_id', $id)->pluck('role_id')->all();

            if ($roleIds !== []) {
                $super = $super || DB::table('cms_roles')
                    ->whereIn('id', $roleIds)
                    ->where('slug', Role::SUPER_ADMIN)
                    ->exists();

                if (! $super) {
                    $slugs = DB::table('cms_role_permissions')
                        ->join('cms_permissions', 'cms_permissions.id', '=', 'cms_role_permissions.permission_id')
                        ->whereIn('cms_role_permissions.role_id', $roleIds)
                        ->pluck('cms_permissions.slug')
                        ->all();
                }
            }
        } catch (\Throwable) {
            // Tables missing/unreadable — fall back to email-only super flag.
        }

        return $this->resolveCache[$id] = [
            'super' => $super,
            'slugs' => array_values(array_unique(array_map('strval', $slugs))),
        ];
    }

    private function systemInitialized(): bool
    {
        if ($this->initialized !== null) {
            return $this->initialized;
        }

        try {
            return $this->initialized = Schema::hasTable('cms_roles')
                && DB::table('cms_roles')->exists();
        } catch (\Throwable) {
            return $this->initialized = false;
        }
    }

    private function emailIsSuper(Authenticatable $user): bool
    {
        $email = $this->userEmail($user);

        if ($email === null) {
            return false;
        }

        $configured = array_map(
            static fn ($e): string => strtolower(trim((string) $e)),
            (array) config('cms.super_admin_emails', []),
        );

        return in_array(strtolower($email), $configured, true);
    }

    private function userEmail(Authenticatable $user): ?string
    {
        if (method_exists($user, 'getAttribute')) {
            $value = $user->getAttribute('email');

            return is_string($value) && $value !== '' ? $value : null;
        }

        return null;
    }

    private function userId(Authenticatable $user): ?int
    {
        $id = $user->getAuthIdentifier();

        if (is_int($id)) {
            return $id;
        }

        return is_string($id) && ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The canonical TN CMS permission set (v1.0.0-beta.3).
     */
    private function registerCorePermissions(): void
    {
        $core = [
            // Dashboard
            ['admin.access', 'Access admin panel', 'Dashboard'],

            // Content (generic)
            ['content.view', 'View content', 'Content'],
            ['content.create', 'Create content', 'Content'],
            ['content.edit', 'Edit content', 'Content'],
            ['content.delete', 'Delete content', 'Content'],
            ['content.publish', 'Publish content', 'Content'],
            ['content.authority.recover', 'Detach extension-managed content', 'Content'],
            ['content.ownership.adopt', 'Let an extension adopt existing content', 'Content'],

            // Pages
            ['pages.view', 'View pages', 'Pages'],
            ['pages.create', 'Create pages', 'Pages'],
            ['pages.edit', 'Edit pages', 'Pages'],
            ['pages.delete', 'Delete pages', 'Pages'],
            ['pages.publish', 'Publish pages', 'Pages'],
            ['pages.edit_others', "Edit other users' pages", 'Pages'],
            ['pages.delete_others', "Delete other users' pages", 'Pages'],

            // Posts
            ['posts.view', 'View posts', 'Posts'],
            ['posts.create', 'Create posts', 'Posts'],
            ['posts.edit', 'Edit posts', 'Posts'],
            ['posts.delete', 'Delete posts', 'Posts'],
            ['posts.publish', 'Publish posts', 'Posts'],
            ['posts.edit_others', "Edit other users' posts", 'Posts'],
            ['posts.delete_others', "Delete other users' posts", 'Posts'],

            // Taxonomy
            ['taxonomy.manage', 'Manage taxonomies & terms', 'Taxonomy'],

            // Media
            ['media.view', 'View media', 'Media'],
            ['media.upload', 'Upload media', 'Media'],
            ['media.edit', 'Edit media', 'Media'],
            ['media.delete', 'Delete media', 'Media'],

            // Menus
            ['menus.manage', 'Manage menus', 'Menus'],

            // Settings
            ['settings.manage', 'Manage settings', 'Settings'],
            ['languages.manage', 'Manage languages & translation files', 'Settings'],

            // Themes
            ['themes.view', 'View themes', 'Themes'],
            ['themes.activate', 'Activate themes', 'Themes'],
            ['themes.install', 'Install themes', 'Themes'],
            ['themes.delete', 'Delete themes', 'Themes'],
            ['theme_options.manage', 'Manage theme options', 'Themes'],
            ['themes.import', 'Import theme & plugin demos', 'Themes'],
            ['widgets.manage', 'Manage widgets', 'Themes'],

            // Plugins
            ['plugins.view', 'View plugins', 'Plugins'],
            ['plugins.activate', 'Activate / deactivate plugins', 'Plugins'],
            ['plugins.install', 'Install plugins', 'Plugins'],
            ['plugins.delete', 'Delete plugins', 'Plugins'],

            // Users
            ['users.view', 'View users', 'Users'],
            ['users.create', 'Create users', 'Users'],
            ['users.edit', 'Edit users', 'Users'],
            ['users.delete', 'Delete users', 'Users'],
            ['roles.manage', 'Manage roles & permissions', 'Users'],

            // System
            ['system.health', 'View system health', 'System'],
            ['system.maintenance.manage', 'Manage maintenance mode', 'System'],
            ['system.maintenance.bypass', 'Bypass maintenance mode', 'System'],
            ['system.upgrade.manage', 'Run Core upgrades', 'System'],
        ];

        foreach ($core as [$slug, $name, $group]) {
            $this->register($slug, $name, $group);
        }
    }
}
