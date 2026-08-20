<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $type
 * @property string $content_type
 * @property string $slug
 * @property bool $hierarchical
 * @property bool $is_core
 * @property int $sort_order
 */
class Taxonomy extends Model
{
    protected $table = 'cms_taxonomies';

    protected $fillable = [
        'type',
        'content_type',
        'slug',
        'hierarchical',
        'is_core',
        'sort_order',
    ];

    protected $casts = [
        'hierarchical' => 'boolean',
        'is_core' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class, 'taxonomy_id');
    }

    /**
     * Whether this taxonomy's terms form a parent/child tree.
     *
     * This is the single switch that turns hierarchy on for ANY taxonomy —
     * core (category) or a future custom one (product category, documentation
     * category, …). Hierarchy is never hardcoded per taxonomy type; everything
     * downstream (parent selector, tree rendering, loop guards, featured image)
     * keys off this flag. Tags stay flat purely because their row has it false.
     */
    public function isHierarchical(): bool
    {
        return (bool) $this->hierarchical;
    }

    public function scopeForContentType(Builder $query, string $contentType): Builder
    {
        return $query->where('content_type', $contentType);
    }

    public function scopeCategories(Builder $query): Builder
    {
        return $query->where('type', 'category');
    }

    public function scopeTags(Builder $query): Builder
    {
        return $query->where('type', 'tag');
    }
}
