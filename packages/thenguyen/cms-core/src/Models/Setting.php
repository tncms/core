<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $group
 * @property string $key
 * @property string|null $value
 * @property string $type
 * @property bool $is_public
 * @property bool $autoload
 * @property string|null $description
 */
class Setting extends Model
{
    protected $table = 'cms_settings';

    protected $fillable = [
        'group',
        'key',
        'value',
        'type',
        'is_public',
        'autoload',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'autoload' => 'boolean',
    ];

    public function fullKey(): string
    {
        return $this->group ? $this->group . '.' . $this->key : $this->key;
    }
}
