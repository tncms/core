<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group that buckets user profile field definitions
 * (Account Profile Field Engine, P1-A). Localized labels live in
 * {@see UserProfileFieldTranslation}.
 */
class UserProfileFieldGroup extends Model
{
    protected $table = 'cms_user_profile_field_groups';

    protected $fillable = [
        'key',
        'source',
        'sort_order',
        'is_active',
        'is_locked',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
    ];

    /** @return HasMany<UserProfileField> */
    public function fields(): HasMany
    {
        return $this->hasMany(UserProfileField::class, 'group_id');
    }
}
