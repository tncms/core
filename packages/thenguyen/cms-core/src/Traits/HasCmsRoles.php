<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Services\PermissionManager;

/**
 * Adds CMS role/permission awareness to a user model (v1.0.0-beta.3).
 *
 * Applied to App\Models\User. All permission logic is delegated to the
 * PermissionManager so the rules (super-admin bypass, config-email fallback,
 * fail-open before the RBAC system is initialised) live in exactly one place.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role> $roles
 */
trait HasCmsRoles
{
    /**
     * Roles assigned to this user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'cms_role_user',
            'user_id',
            'role_id',
        )->withTimestamps();
    }

    public function hasRole(string $slug): bool
    {
        return $this->roles()->where('slug', $slug)->exists();
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function hasAnyRole(array $slugs): bool
    {
        if ($slugs === []) {
            return false;
        }

        return $this->roles()->whereIn('slug', $slugs)->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissionManager()->userCan($this, $permission);
    }

    /**
     * True for the super-admin role or a configured super-admin email.
     */
    public function isSuperAdmin(): bool
    {
        return $this->permissionManager()->isSuperAdmin($this);
    }

    private function permissionManager(): PermissionManager
    {
        /** @var PermissionManager $manager */
        $manager = app('cms.permission');

        return $manager;
    }
}
