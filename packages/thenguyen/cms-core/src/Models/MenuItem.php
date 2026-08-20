<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $menu_id
 * @property int|null $parent_id
 * @property string $type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $url
 * @property string $target
 * @property string|null $css_class
 * @property string|null $icon
 * @property array<string, mixed>|null $meta
 * @property int $sort_order
 * @property bool $is_active
 */
class MenuItem extends Model
{
    use SoftDeletes;

    /** Allowed display types (Phase 11B). */
    public const DISPLAY_TYPES = ['normal', 'dropdown', 'mega'];

    /** Allowed mega-panel widths. */
    public const MEGA_WIDTHS = ['content', 'wide', 'full'];

    /** Allowed mega column counts. */
    public const MEGA_COLUMNS = [2, 3, 4, 5, 6];

    /**
     * Allowed mega content sources. `children`/`widget_area` are 11B/11C; the
     * post-driven sources are Phase 11D (resolved in core, never in Blade).
     */
    public const MEGA_SOURCES = ['children', 'widget_area', 'latest_posts', 'categories', 'tags', 'manual_posts'];

    /** Post-driven mega sources whose items are resolved by MenuMegaDataProvider (Phase 11D). */
    public const MEGA_DYNAMIC_SOURCES = ['latest_posts', 'categories', 'tags', 'manual_posts'];

    /** Allowed dynamic mega orderings (Phase 11D). */
    public const MEGA_ORDERS = ['latest', 'oldest', 'most_viewed', 'most_commented', 'random', 'title'];

    /** Dynamic mega item-count bounds (Phase 11D). */
    public const MEGA_LIMIT_MIN = 1;

    public const MEGA_LIMIT_MAX = 24;

    public const MEGA_LIMIT_DEFAULT = 6;

    protected $table = 'cms_menu_items';

    protected $fillable = [
        'menu_id',
        'parent_id',
        'type',
        'reference_type',
        'reference_id',
        'url',
        'target',
        'css_class',
        'icon',
        'meta',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'meta' => 'array',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MenuItemTranslation::class, 'menu_item_id');
    }

    /**
     * Translated title for the given locale. Falls back to the first
     * available translation, then to a deterministic placeholder.
     */
    public function displayTitle(?string $locale = 'vi'): string
    {
        $translation = $this->resolveTranslation($locale);

        $title = $translation?->title;

        return $title !== null && $title !== ''
            ? $title
            : 'Item #'.$this->id;
    }

    /**
     * Resolve the final, locale-aware URL for this item.
     *
     * - custom: the locale's own URL from cms_menu_item_translations (exact
     *   locale, no cross-locale fallback), then the legacy shared column.
     * - page/post: content_url($content, $locale) — the localized slug with the
     *   /{locale}/ prefix applied.
     * - category/tag: term_url($term, $locale) — same, for the term.
     * - fallback: '#'.
     */
    public function resolvedUrl(?string $locale = null): string
    {
        $locale ??= app('cms.language')->currentCode();

        if ($this->type === 'custom') {
            return $this->customUrl($locale);
        }

        $entityUrl = $this->entityUrl($locale);

        if ($entityUrl !== null) {
            return $entityUrl;
        }

        return $this->url ?: '#';
    }

    /**
     * The custom URL for the exact locale (no cross-locale fallback, so one
     * locale's URL never leaks into another), then the legacy shared column.
     */
    private function customUrl(string $locale): string
    {
        $translation = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('locale', $locale)
            : $this->translations()->where('locale', $locale)->first();

        $url = $translation?->url;

        if ($url === null || $url === '') {
            $url = $this->url; // legacy shared column (pre-localized data)
        }

        return ($url !== null && $url !== '') ? $url : '#';
    }

    /**
     * The localized URL for an entity-linked item, computed from the referenced
     * record per locale, or null when it cannot be resolved.
     */
    private function entityUrl(string $locale): ?string
    {
        if ($this->reference_id === null) {
            return null;
        }

        if ($this->reference_type === 'content') {
            $content = Content::query()->find($this->reference_id);
            $url = $content !== null ? content_url($content, $locale) : '#';

            return $url !== '#' ? $url : null;
        }

        if ($this->reference_type === 'term') {
            $term = Term::query()->with('taxonomy')->find($this->reference_id);
            $url = $term !== null ? term_url($term, $locale) : '#';

            return $url !== '#' ? $url : null;
        }

        return null;
    }

    /**
     * Normalized presentational metadata with safe fallbacks (Phase 11B–11D).
     *
     * Invalid or missing values collapse to defaults, so the frontend and admin
     * always receive a well-formed shape regardless of what is persisted. The
     * `icon` comes from the dedicated column (with a meta fallback for imports).
     *
     * `badge`/`description` may be stored either as a scalar string (legacy) or a
     * locale map ({@see resolveLocalizedMeta}); both resolve to a single string
     * for $locale. Dynamic-source keys (mega_limit/order/ids) are only meaningful
     * when mega_source is a post-driven source, but are always normalized.
     *
     * @return array{display: string, mega_columns: int, mega_width: string, mega_source: string, mega_widget_area: ?string, mega_limit: int, mega_order_by: string, mega_category_ids: array<int, int>, mega_tag_ids: array<int, int>, mega_post_ids: array<int, int>, badge: ?string, icon: ?string, description: ?string}
     */
    public function resolvedMeta(?string $locale = null): array
    {
        $locale ??= app('cms.language')->currentCode();
        $meta = is_array($this->meta) ? $this->meta : [];

        $display = $meta['display'] ?? 'normal';
        if (! in_array($display, self::DISPLAY_TYPES, true)) {
            $display = 'normal';
        }

        $columns = (int) ($meta['mega_columns'] ?? 4);
        if (! in_array($columns, self::MEGA_COLUMNS, true)) {
            $columns = 4;
        }

        $width = $meta['mega_width'] ?? 'wide';
        if (! in_array($width, self::MEGA_WIDTHS, true)) {
            $width = 'wide';
        }

        $source = $meta['mega_source'] ?? 'children';
        if (! in_array($source, self::MEGA_SOURCES, true)) {
            $source = 'children';
        }

        $orderBy = $meta['mega_order_by'] ?? 'latest';
        if (! in_array($orderBy, self::MEGA_ORDERS, true)) {
            $orderBy = 'latest';
        }

        $limit = (int) ($meta['mega_limit'] ?? self::MEGA_LIMIT_DEFAULT);
        $limit = max(self::MEGA_LIMIT_MIN, min($limit, self::MEGA_LIMIT_MAX));

        return [
            'display' => $display,
            'mega_columns' => $columns,
            'mega_width' => $width,
            'mega_source' => $source,
            'mega_widget_area' => $this->cleanMetaString($meta['mega_widget_area'] ?? null),
            'mega_limit' => $limit,
            'mega_order_by' => $orderBy,
            'mega_category_ids' => $this->cleanMetaIds($meta['mega_category_ids'] ?? null),
            'mega_tag_ids' => $this->cleanMetaIds($meta['mega_tag_ids'] ?? null),
            'mega_post_ids' => $this->cleanMetaIds($meta['mega_post_ids'] ?? null),
            'badge' => $this->resolveLocalizedMeta($meta['badge'] ?? null, $locale),
            'icon' => $this->cleanMetaString($this->icon ?? ($meta['icon'] ?? null)),
            'description' => $this->resolveLocalizedMeta($meta['description'] ?? null, $locale),
        ];
    }

    /**
     * Resolve a scalar-or-localized meta value to a single string for $locale.
     *
     * Fallback chain: current locale → default locale → first available →
     * scalar fallback → null. A scalar string is returned as-is (legacy data); a
     * locale map ({vi: …, en: …}) resolves through the chain; anything else null.
     */
    private function resolveLocalizedMeta(mixed $value, string $locale): ?string
    {
        if (is_string($value)) {
            return $this->cleanMetaString($value);
        }

        if (! is_array($value)) {
            return null;
        }

        $exact = $this->cleanMetaString($value[$locale] ?? null);
        if ($exact !== null) {
            return $exact;
        }

        $default = $this->cleanMetaString($value[app('cms.language')->defaultCode()] ?? null);
        if ($default !== null) {
            return $default;
        }

        foreach ($value as $candidate) {
            $clean = $this->cleanMetaString($candidate);
            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Normalize a stored id list into a clean list of positive, de-duplicated
     * ints (tolerating numeric strings from form/JSON state).
     *
     * @return array<int, int>
     */
    private function cleanMetaIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_numeric($item) && (int) $item > 0) {
                $out[] = (int) $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Trim a metadata string; empty or non-string values become null.
     */
    private function cleanMetaString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function resolveTranslation(?string $locale): ?MenuItemTranslation
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
