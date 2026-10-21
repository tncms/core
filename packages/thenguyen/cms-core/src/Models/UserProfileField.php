<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user profile field definition (Account Profile Field Engine, P1-A).
 * Typed, declaratively validated, privacy-first (visibility defaults to owner).
 */
class UserProfileField extends Model
{
    protected $table = 'cms_user_profile_fields';

    protected $fillable = [
        'key',
        'group_id',
        'type',
        'options',
        'validation',
        'default_value',
        'visibility',
        'editability',
        'is_encrypted',
        'source',
        'sort_order',
        'is_active',
        'is_locked',
        'schema_revision',
    ];

    protected $casts = [
        'options' => 'array',
        'validation' => 'array',
        'default_value' => 'array',
        'is_encrypted' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'schema_revision' => 'integer',
    ];

    /** @return BelongsTo<UserProfileFieldGroup, UserProfileField> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(UserProfileFieldGroup::class, 'group_id');
    }

    /** @return HasMany<UserProfileValue> */
    public function values(): HasMany
    {
        return $this->hasMany(UserProfileValue::class, 'field_id');
    }
}
