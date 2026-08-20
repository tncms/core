<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A per-locale value for a cms_settings key (v1.0.0-beta.6.2).
 *
 * @property int $id
 * @property string $key
 * @property string $locale
 * @property string|null $value
 * @property string $type
 */
class SettingTranslation extends Model
{
    protected $table = 'cms_settings_translate';

    protected $fillable = [
        'key',
        'locale',
        'value',
        'type',
    ];
}
