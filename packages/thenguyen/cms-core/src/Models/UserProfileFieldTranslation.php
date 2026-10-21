<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Localized label/help/option-labels for a profile group or field
 * (Account Profile Field Engine, P1-A). owner_type is 'group' | 'field'.
 */
class UserProfileFieldTranslation extends Model
{
    protected $table = 'cms_user_profile_field_translations';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'locale',
        'label',
        'help',
        'options_labels',
    ];

    protected $casts = [
        'owner_id' => 'integer',
        'options_labels' => 'array',
    ];
}
