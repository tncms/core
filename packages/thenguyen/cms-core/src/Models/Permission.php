<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A single, string-keyed capability (v1.0.0-beta.3).
 *
 * Permissions are identified by their immutable `slug` (e.g. "themes.install").
 * `group` is a display-only bucket used to organise the role editor checkboxes
 * (e.g. "Themes", "Plugins"). The canonical list is declared in code by the
 * PermissionManager registry and mirrored into this table by syncDefaults();
 * plugins may register additional permissions at runtime.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $group
 * @property string|null $description
 */
class Permission extends Model
{
    protected $table = 'cms_permissions';

    protected $fillable = [
        'name',
        'slug',
        'group',
        'description',
    ];

    /**
     * Roles that grant this permission.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'cms_role_permissions',
            'permission_id',
            'role_id',
        )->withTimestamps();
    }
}
