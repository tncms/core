<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use TheNguyen\CMS\Revision\Contracts\RevisionableInterface;
use TheNguyen\CMS\Taxonomy\Translation\Adapters\TermTranslationReadAdapter;
use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationEntityInterface;
use TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields;

/**
 * @property int $id
 * @property int $taxonomy_id
 * @property int|null $parent_id
 * @property string|null $featured_image
 * @property int $sort_order
 * @property int $count
 */
class Term extends Model implements RevisionableInterface, TaxonomyTranslationEntityInterface
{
    use SoftDeletes;

    protected $table = 'cms_terms';

    protected $fillable = [
        'taxonomy_id',
        'parent_id',
        'featured_image',
        'sort_order',
        'count',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'count' => 'integer',
    ];

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(TermTranslation::class, 'term_id');
    }

    public function translation(?string $locale = null): HasOne
    {
        $locale ??= app('cms.language')->defaultCode();

        return $this->hasOne(TermTranslation::class, 'term_id')
            ->where('locale', $locale);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class, 'cms_content_terms', 'term_id', 'content_id')
            ->withTimestamps();
    }

    // ── TaxonomyTranslationEntityInterface (Phase 9.2B) ──────────────────────────
    //
    // Entity-side facts the Translation Platform needs to localize this term. Pure
    // accessors over existing data — no behaviour change. The platform NEVER
    // branches on the concrete taxonomy type; it only forwards it.

    /** The term's primary key — the translation driver's entity id. */
    public function getTranslationKey(): int|string
    {
        return $this->getKey();
    }

    /** The generic taxonomy type this term belongs to (opaque selector). */
    public function getTaxonomyType(): string
    {
        return (string) ($this->taxonomy?->type ?? '');
    }

    /**
     * The locales this term may hold a translation for (the site's public locales).
     *
     * @return array<int, string>
     */
    public function translatableLocales(): array
    {
        return app('cms.language')->getPublicLocales();
    }

    // ── RevisionableInterface (Phase 9.2G) ───────────────────────────────────────
    //
    // Taxonomy terms opt into locale-aware revisions. The snapshot captures ONLY the
    // localized taxonomy content (name/slug/description/SEO — the canonical
    // TaxonomyTranslationFields), NEVER taxonomy structure (parent_id, hierarchy,
    // children, ordering, count, relationships, permissions). Recording is wired +
    // gated in TaxonomyManager (revisions.entities.taxonomy). This contract adds no
    // columns and is taxonomy-agnostic — every taxonomy snapshots identically,
    // discriminated only by the term id.

    /** Stable polymorphic revision type for every taxonomy term. */
    public function revisionEntityType(): string
    {
        return 'term';
    }

    /** The term's stable primary key. */
    public function revisionEntityId(): int|string
    {
        return $this->getKey();
    }

    /**
     * The locales this term may be revised for (the site's public locales).
     *
     * @return array<int, string>
     */
    public function revisionableLocales(): array
    {
        return app('cms.language')->getPublicLocales();
    }

    /**
     * The localized field map persisted for ONE locale — exactly the translation
     * row the write produced, restricted to the canonical taxonomy vocabulary
     * (name/slug/description/meta_title/meta_description). Structure columns are
     * intentionally excluded. An unauthored locale returns an empty map.
     *
     * @return array<string, mixed>
     */
    public function snapshotForLocale(string $locale): array
    {
        $translation = $this->translations()->where('locale', $locale)->first();

        if ($translation === null) {
            return [];
        }

        $snapshot = [];
        foreach (TaxonomyTranslationFields::all() as $field) {
            $snapshot[$field] = $translation->{$field} ?? null;
        }

        return $snapshot;
    }

    /**
     * Whether this term's taxonomy is hierarchical. Convenience delegate so
     * callers holding a Term don't have to reach through the relation.
     */
    public function isHierarchical(): bool
    {
        return (bool) ($this->taxonomy?->isHierarchical() ?? false);
    }

    /**
     * Ancestors from the immediate parent up to the root, in that order.
     *
     * Walks the parent_id chain with a visited-id guard so a corrupt loop in
     * the data can never spin forever — it simply stops when it revisits a
     * term. Hierarchy is global, so this is locale-independent.
     *
     * @return list<Term>
     */
    public function ancestors(): array
    {
        $ancestors = [];
        $seen = [$this->id => true];
        $current = $this->parent;

        while ($current !== null && ! isset($seen[$current->id])) {
            $ancestors[] = $current;
            $seen[$current->id] = true;
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Ids of every descendant (children, grandchildren, …) of this term.
     *
     * Breadth-first over the children relation with a visited-id guard. Used by
     * the parent-selector (to exclude invalid choices) and the loop guard (a
     * term may never be re-parented under one of its own descendants).
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $collected = [];
        $queue = [$this->id];
        $seen = [$this->id => true];

        while ($queue !== []) {
            $childIds = static::query()
                ->whereIn('parent_id', $queue)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $queue = [];

            foreach ($childIds as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }

                $seen[$childId] = true;
                $collected[] = $childId;
                $queue[] = $childId;
            }
        }

        return $collected;
    }

    /**
     * Depth in the tree (0 for a root term). Convenience for indented rendering.
     */
    public function depth(): int
    {
        return count($this->ancestors());
    }

    /**
     * Translated description for the given locale, using the same fallback
     * chain as displayName (exact locale → first available → ''). The stored
     * value is already sanitized HTML (see TaxonomyManager), safe to render.
     */
    public function translatedDescription(?string $locale = null): string
    {
        if ($adapter = $this->termsReadAdapter()) {
            return (string) ($adapter->description($this, $locale) ?? '');
        }

        return (string) ($this->resolveTranslation($locale)?->description ?? '');
    }

    /**
     * The term's featured image URL, or null when none is set. Global across
     * locales (stored on the term, not the translation).
     */
    public function featuredImageUrl(): ?string
    {
        $url = $this->featured_image;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Translated display name for the given locale. Falls back to the
     * first available translation, then to a deterministic placeholder.
     */
    public function displayName(?string $locale = null): string
    {
        if ($adapter = $this->termsReadAdapter()) {
            return $adapter->name($this, $locale);
        }

        $translation = $this->resolveTranslation($locale);

        $name = $translation?->name;

        return $name !== null && $name !== ''
            ? $name
            : 'Term #'.$this->id;
    }

    /**
     * Translated slug for the given locale. Falls back to the first
     * available translation, then to an empty string.
     */
    public function translatedSlug(?string $locale = null): string
    {
        if ($adapter = $this->termsReadAdapter()) {
            return $adapter->slug($this, $locale);
        }

        $translation = $this->resolveTranslation($locale);

        return (string) ($translation?->slug ?? '');
    }

    /**
     * Slug for the EXACT locale (no fallback). Null when that locale has no
     * translation — used by locale-aware URL helpers to detect missing
     * translations.
     */
    public function localeSlug(string $locale): ?string
    {
        if ($adapter = $this->termsReadAdapter()) {
            return $adapter->localeSlug($this, $locale);
        }

        $translation = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('locale', $locale)
            : $this->translations()->where('locale', $locale)->first();

        $slug = $translation?->slug;

        return ($slug !== null && $slug !== '') ? $slug : null;
    }

    /**
     * Name for the EXACT locale (no fallback). Null when that locale has no
     * translation — lets callers show a category strictly in the current
     * language instead of falling back to another locale's name.
     */
    public function localeName(string $locale): ?string
    {
        if ($adapter = $this->termsReadAdapter()) {
            return $adapter->localeName($this, $locale);
        }

        $translation = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('locale', $locale)
            : $this->translations()->where('locale', $locale)->first();

        $name = $translation?->name;

        return ($name !== null && $name !== '') ? $name : null;
    }

    public function hasTranslation(string $locale): bool
    {
        if ($adapter = $this->termsReadAdapter()) {
            return $adapter->hasTranslation($this, $locale);
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->contains('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->exists();
    }

    private function resolveTranslation(?string $locale): ?TermTranslation
    {
        $locale ??= app('cms.language')->defaultCode();

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
        }

        return $this->translations()->where('locale', $locale)->first()
            ?? $this->translations()->first();
    }

    /**
     * The Taxonomy Read Adapter when it is active (Phase 9.2D), else null so the
     * legacy body runs. Flag-gated by translation.modules.terms.read_driver — the
     * default 'legacy' keeps every read on the legacy path (zero behaviour change).
     */
    private function termsReadAdapter(): ?TermTranslationReadAdapter
    {
        /** @var TermTranslationReadAdapter $adapter */
        $adapter = app('cms.translation.terms_read_adapter');

        return $adapter->isActive() ? $adapter : null;
    }
}
