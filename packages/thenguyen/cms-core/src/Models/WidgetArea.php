<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $source
 * @property string|null $source_slug
 * @property bool $is_active
 * @property int $sort_order
 */
class WidgetArea extends Model
{
    protected $table = 'cms_widget_areas';

    protected $fillable = [
        'slug',
        'name',
        'description',
        'source',
        'source_slug',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function widgets(): HasMany
    {
        return $this->hasMany(Widget::class, 'area_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
