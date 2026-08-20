<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string|null $locale
 * @property string $name
 * @property string|null $native_name
 * @property string|null $flag
 * @property string $direction
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 */
class Language extends Model
{
    protected $table = 'cms_languages';

    protected $fillable = [
        'code',
        'locale',
        'name',
        'native_name',
        'flag',
        'direction',
        'is_default',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    /**
     * "Native (code)" — used for admin selectors.
     */
    public function label(): string
    {
        return $this->displayName() . ' (' . $this->code . ')';
    }

    /**
     * Human-facing language name (native name preferred, then name, then code).
     */
    public function displayName(): string
    {
        $native = is_string($this->native_name) ? trim($this->native_name) : '';

        if ($native !== '') {
            return $native;
        }

        $name = trim((string) $this->name);

        return $name !== '' ? $name : $this->code;
    }
}
