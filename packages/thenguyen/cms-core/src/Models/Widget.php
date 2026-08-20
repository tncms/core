<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A widget instance placed in an area.
 *
 * @property int $id
 * @property int|null $area_id
 * @property string $widget_type
 * @property string|null $title
 * @property array<string, mixed>|null $settings
 * @property int $sort_order
 * @property bool $is_active
 */
class Widget extends Model
{
    protected $table = 'cms_widgets';

    protected $fillable = [
        'area_id',
        'widget_type',
        'title',
        'settings',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(WidgetArea::class, 'area_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(WidgetTranslation::class, 'widget_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The translation row for the exact locale (no fallback), or null. Honours
     * an eager-loaded translations relation so render paths avoid N+1 queries.
     */
    public function translationFor(?string $locale): ?WidgetTranslation
    {
        if ($locale === null || $locale === '') {
            return null;
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }
}
