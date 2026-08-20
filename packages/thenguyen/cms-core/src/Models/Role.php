<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named bundle of permissions that can be assigned to users (v1.0.0-beta.3).
 *
 * The "super-admin" slug is special: it grants every permission implicitly (see
 * {@see isSuperAdmin()} and PermissionManager), regardless of the rows in
 * cms_role_permissions. System roles (is_system = true) are protected from
 * deletion in the admin UI.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends Model
{
    public const SUPER_ADMIN = 'super-admin';

    protected $table = 'cms_roles';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    /**
     * Permissions granted by this role.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'cms_role_permissions',
            'role_id',
            'permission_id',
        )->withTimestamps();
    }

    /**
     * Users assigned this role. Points at the host app's configured user model.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            $this->userModel(),
            'cms_role_user',
            'role_id',
            'user_id',
        )->withTimestamps();
    }

    /**
     * The super-admin role implicitly holds every permission.
     */
    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN;
    }

    /**
     * Resolve the host application's user model class.
     *
     * @return class-string<Model>
     */
    private function userModel(): string
    {
        $model = config('auth.providers.users.model', \App\Models\User::class);

        return is_string($model) ? $model : \App\Models\User::class;
    }
}
