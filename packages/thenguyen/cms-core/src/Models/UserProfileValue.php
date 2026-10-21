<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-user typed profile value (Account Profile Field Engine, P1-A).
 *
 * `locale` uses '' as the locale-neutral sentinel (never NULL) so
 * UNIQUE(user_id, field_id, locale) is enforceable on MySQL and SQLite alike.
 * No read/write authorization lives here — P1-A ships the storage only; the
 * privacy-aware value API arrives in P1-B (UserProfileManager).
 */
class UserProfileValue extends Model
{
    protected $table = 'cms_user_profile_values';

    protected $fillable = [
        'user_id',
        'field_id',
        'field_key',
        'locale',
        'value',
        'value_index',
        'updated_by',
    ];

    protected $attributes = [
        'locale' => '',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'field_id' => 'integer',
        'value' => 'array',
        'updated_by' => 'integer',
    ];

    /** @return BelongsTo<UserProfileField, UserProfileValue> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(UserProfileField::class, 'field_id');
    }
}
