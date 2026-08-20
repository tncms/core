<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $slug
 * @property string|null $location
 * @property string $status
 * @property bool $is_system
 * @property int $sort_order
 */
class Menu extends Model
{
    use SoftDeletes;

    protected $table = 'cms_menus';

    protected $fillable = [
        'slug',
        'location',
        'status',
        'is_system',
        'sort_order',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(MenuTranslation::class, 'menu_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'menu_id');
    }

    /**
     * Top-level menu items (no parent), ordered for display.
     */
    public function rootItems(): HasMany
    {
        return $this->items()
            ->whereNull('parent_id')
            ->orderBy('sort_order');
    }

    /**
     * Translated display name for the given locale. Falls back to the first
     * available translation, then to a deterministic placeholder.
     */
    public function displayName(?string $locale = 'vi'): string
    {
        $translation = $this->resolveTranslation($locale);

        $name = $translation?->name;

        return $name !== null && $name !== ''
            ? $name
            : 'Menu #' . $this->id;
    }

    private function resolveTranslation(?string $locale): ?MenuTranslation
    {
        $locale ??= 'vi';

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
        }

        return $this->translations()->where('locale', $locale)->first()
            ?? $this->translations()->first();
    }
}
