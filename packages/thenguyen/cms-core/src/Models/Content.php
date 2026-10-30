<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Contracts\Previewable;
use TheNguyen\CMS\Revision\Contracts\RevisionableInterface;

/**
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int|null $author_id
 * @property int|null $parent_id
 * @property string|null $template
 * @property string|null $featured_image
 * @property int $sort_order
 * @property string $comment_status
 * @property int $views_count
 * @property int $comments_count
 * @property bool $is_featured
 * @property bool $show_page_title
 * @property \Illuminate\Support\Carbon|null $published_at
 */
class Content extends Model implements Previewable, RevisionableInterface
{
    use SoftDeletes;

    protected $table = 'cms_contents';

    protected $fillable = [
        'type',
        'status',
        'author_id',
        'parent_id',
        'template',
        'featured_image',
        'sort_order',
        'comment_status',
        'views_count',
        'comments_count',
        'is_featured',
        'show_page_title',
        'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'sort_order' => 'integer',
        'views_count' => 'integer',
        'comments_count' => 'integer',
        'is_featured' => 'boolean',
        'show_page_title' => 'boolean',
        'revision' => 'integer',
    ];

    /**
     * Fire content delete lifecycle actions (v1.0.0-beta.7.1.12.2). Bound to the
     * model's Eloquent events so EVERY delete path — Filament DeleteAction, the
     * ContentManager, a cascade, or a programmatic delete — emits the hooks
     * exactly once. Best-effort: a broken listener never blocks a delete.
     *
     * Also the model-level backstop of CORE-STRUCTURED-CONTENT-AUTHORITY-R1: an
     * Eloquent write to EXTENSION-MANAGED content (or one that sets/changes the
     * `managed_by` marker) outside a ContentManager write is refused. Ordinary
     * content is unaffected. Query-builder/raw SQL and pivot writes do not fire
     * model events and are outside this boundary.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $content): void {
            self::assertSanctionedWrite($content);
        });

        static::restoring(static function (self $content): void {
            self::assertSanctionedWrite($content);
        });

        static::deleting(static function (self $content): void {
            self::assertSanctionedWrite($content);
            self::fireContentLifecycle('cms.content.deleting', $content);
        });

        static::deleted(static function (self $content): void {
            self::fireContentLifecycle('cms.content.deleted', $content);
        });
    }

    /** Refuse an unsanctioned Eloquent write to managed content or to the marker. */
    public static function assertSanctionedWrite(self $content): void
    {
        $managed = $content->getOriginal('managed_by') !== null || $content->isDirty('managed_by');

        if (! $managed) {
            return;
        }

        $id = $content->exists ? (int) $content->getKey() : null;

        if (app('cms.content.gate')->isSanctioned($id)) {
            return;
        }

        throw new \TheNguyen\CMS\ContentAuthority\ContentWriteDenied(
            'unsanctioned_write',
            'This content is managed by an extension and can only be changed through the content service.',
            $id,
            (string) ($content->getOriginal('managed_by') ?? $content->managed_by),
        );
    }

    private static function fireContentLifecycle(string $hook, self $content): void
    {
        try {
            if (function_exists('do_action') && function_exists('hook_context')) {
                do_action($hook, $content, hook_context(['content' => $content]));
            }
        } catch (\Throwable) {
            // Lifecycle notification must never break the delete itself.
        }
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ContentTranslation::class, 'content_id');
    }

    // --- Revisionable (Phase 9.0F Posts, 9.1F Pages) -----------------------
    // Additive: the entity owns exactly what a per-locale snapshot captures, so
    // the revision layer stays above the storage/driver layer. These methods are
    // type-agnostic (a post and a page snapshot identically, discriminated by the
    // Content id); recording is wired per-type + gated in ContentManager. This
    // contract adds no columns.

    public function revisionEntityType(): string
    {
        return 'content';
    }

    public function revisionEntityId(): int|string
    {
        return $this->getKey();
    }

    /** @return array<int, string> */
    public function revisionableLocales(): array
    {
        return app('cms.language')->getPublicLocales();
    }

    /**
     * The localized field map persisted for one locale — the exact translation
     * row the write produced. An unauthored locale returns an empty map.
     *
     * @return array<string, mixed>
     */
    public function snapshotForLocale(string $locale): array
    {
        $translation = $this->translations()->where('locale', $locale)->first();

        if ($translation === null) {
            return [];
        }

        return [
            'title' => $translation->title,
            'slug' => $translation->slug,
            'excerpt' => $translation->excerpt,
            'content' => $translation->content,
            'meta_title' => $translation->meta_title,
            'meta_description' => $translation->meta_description,
            'meta_keywords' => $translation->meta_keywords,
        ];
    }

    public function translation(?string $locale = null): HasOne
    {
        $locale ??= app('cms.language')->defaultCode();

        return $this->hasOne(ContentTranslation::class, 'content_id')
            ->where('locale', $locale);
    }

    public function terms(): BelongsToMany
    {
        return $this->belongsToMany(Term::class, 'cms_content_terms', 'content_id', 'term_id')
            ->withTimestamps();
    }

    public function author(): BelongsTo
    {
        $userModel = config('auth.providers.users.model', \App\Models\User::class);

        return $this->belongsTo($userModel, 'author_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopePages(Builder $query): Builder
    {
        return $query->where('type', 'page');
    }

    public function scopePosts(Builder $query): Builder
    {
        return $query->where('type', 'post');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * Exclude plugin-managed content that is not publicly available right now
     * (CORE-CONTENT-EXT-R1). Chain after published() on every public discovery
     * surface; Core content is never affected.
     */
    public function scopeDiscoverable(Builder $query, ?string $locale = null): Builder
    {
        return app('cms.content_ownership')->constrainDiscoverable($query, $locale);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * Only content explicitly flagged as featured (the `is_featured` column —
     * NOT "has a featured image").
     */
    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * Most-viewed first. Ties fall back to newest, then id, for a stable order.
     */
    public function scopeMostViewed(Builder $query): Builder
    {
        return $query->orderByDesc('views_count')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Most-commented first. Ties fall back to newest, then id, for a stable order.
     */
    public function scopeMostCommented(Builder $query): Builder
    {
        return $query->orderByDesc('comments_count')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Translated title for the given locale. Falls back to the first
     * available translation, then to a deterministic placeholder.
     */
    public function translatedTitle(?string $locale = null): string
    {
        if ($adapter = $this->postsReadAdapter()) {
            return $adapter->title($this, $locale);
        }

        if ($adapter = $this->pagesReadAdapter()) {
            return $adapter->title($this, $locale);
        }

        $translation = $this->resolveTranslation($locale);

        $title = $translation?->title;

        return $title !== null && $title !== ''
            ? $title
            : 'Content #'.$this->id;
    }

    /**
     * Translated slug for the given locale. Falls back to the first
     * available translation, then to an empty string.
     */
    public function translatedSlug(?string $locale = null): string
    {
        if ($adapter = $this->postsReadAdapter()) {
            return $adapter->slug($this, $locale);
        }

        if ($adapter = $this->pagesReadAdapter()) {
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
        if ($adapter = $this->postsReadAdapter()) {
            return $adapter->localeSlug($this, $locale);
        }

        if ($adapter = $this->pagesReadAdapter()) {
            return $adapter->localeSlug($this, $locale);
        }

        $translation = $this->relationLoaded('translations')
            ? $this->translations->firstWhere('locale', $locale)
            : $this->translations()->where('locale', $locale)->first();

        $slug = $translation?->slug;

        return ($slug !== null && $slug !== '') ? $slug : null;
    }

    public function hasTranslation(string $locale): bool
    {
        if ($adapter = $this->postsReadAdapter()) {
            return $adapter->hasTranslation($this, $locale);
        }

        if ($adapter = $this->pagesReadAdapter()) {
            return $adapter->hasTranslation($this, $locale);
        }

        if ($this->relationLoaded('translations')) {
            return $this->translations->contains('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->exists();
    }

    /**
     * The Posts read adapter (Phase 9.0C), or null when it must not be used.
     *
     * Returns the adapter ONLY for posts when `translation.modules.posts.driver`
     * is not `legacy` — so pages, terms, and the default configuration take the
     * unchanged legacy path below. Fully guarded: any resolution problem falls
     * back to legacy, never breaking a read.
     */
    private function postsReadAdapter(): ?\TheNguyen\CMS\Translation\Adapters\PostTranslationReadAdapter
    {
        if ($this->type !== 'post') {
            return null;
        }

        try {
            $adapter = app('cms.translation.posts_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Pages read adapter (Phase 9.1C), or null when it must not be used.
     *
     * Returns the adapter ONLY for pages when `translation.modules.pages.read_driver`
     * is not `legacy` — so posts, terms, and the default configuration take the
     * unchanged legacy path below. Fully guarded: any resolution problem falls back
     * to legacy, never breaking a read. Mirrors {@see postsReadAdapter()}.
     */
    private function pagesReadAdapter(): ?\TheNguyen\CMS\Translation\Adapters\PageTranslationReadAdapter
    {
        if ($this->type !== 'page') {
            return null;
        }

        try {
            $adapter = app('cms.translation.pages_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ---- Previewable (core PreviewManager, v1.0.0-beta.7.1.12.1) ---------
    //
    // Posts register as "cms.post" and pages as "cms.page" with the
    // PreviewManager (see CmsServiceProvider). Core registers a RENDERER for
    // both, so previewRouteName()/previewRouteParameters() are only a soft
    // redirect fallback and are never used for these types.

    public function previewType(): string
    {
        return $this->type === 'post' ? 'cms.post' : 'cms.page';
    }

    public function previewKey(): string|int
    {
        return $this->getKey();
    }

    public function previewRouteName(): string
    {
        if ($this->type === 'post' && Route::has('cms.post')) {
            return 'cms.post';
        }

        return 'cms.resolve';
    }

    /** @return array<string, mixed> */
    public function previewRouteParameters(): array
    {
        return ['slug' => $this->translatedSlug()];
    }

    public function previewTitle(): string
    {
        return $this->translatedTitle();
    }

    public function previewExpiresAt(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes((int) config('cms.preview.default_ttl_minutes', 30));
    }

    private function resolveTranslation(?string $locale): ?ContentTranslation
    {
        $locale ??= app('cms.language')->defaultCode();

        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
        }

        return $this->translations()->where('locale', $locale)->first()
            ?? $this->translations()->first();
    }
}
