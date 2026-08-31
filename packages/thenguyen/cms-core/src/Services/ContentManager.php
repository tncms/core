<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\ContentTranslation;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;

class ContentManager
{
    public function __construct(
        private readonly SlugManager $slugManager,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Content
    {
        $content = DB::transaction(function () use ($data): Content {
            $content = Content::query()->create($this->contentAttributes($data, isNew: true));

            // Hook point: content is being saved (v1.0.0-beta.7.1.11).
            do_action('cms.content.saving', $content, $data, hook_context(['content' => $content, 'data' => $data]));

            $translation = $this->upsertTranslation($content, $data, isNew: true);

            $this->syncTerms($content, $data['term_ids'] ?? null);
            $this->upsertSlug($content, $translation);

            $content->refresh();
            $this->recordRevision($content, $translation->locale, $data);

            return $content;
        });

        do_action('cms.content.saved', $content, $data, hook_context(['content' => $content, 'data' => $data]));

        return $content;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Content $content, array $data): Content
    {
        // Hook point: content is being saved (v1.0.0-beta.7.1.11).
        do_action('cms.content.saving', $content, $data, hook_context(['content' => $content, 'data' => $data]));

        $content = DB::transaction(function () use ($content, $data): Content {
            $content->fill($this->contentAttributes($data, isNew: false, current: $content));
            $content->save();

            $translation = $this->upsertTranslation($content, $data, isNew: false);

            if (array_key_exists('term_ids', $data)) {
                $this->syncTerms($content, $data['term_ids']);
            }

            $this->upsertSlug($content, $translation);

            $content->refresh();
            $this->recordRevision($content, $translation->locale, $data);

            return $content;
        });

        do_action('cms.content.saved', $content, $data, hook_context(['content' => $content, 'data' => $data]));

        return $content;
    }

    public function delete(Content $content): bool
    {
        return DB::transaction(function () use ($content): bool {
            Slug::query()
                ->where('reference_type', 'content')
                ->where('reference_id', $content->id)
                ->delete();

            return (bool) $content->delete();
        });
    }

    public function findBySlug(string $slug, string $locale = 'vi', ?string $type = null): ?Content
    {
        $query = Content::query()
            ->whereHas('translations', function ($q) use ($slug, $locale): void {
                $q->where('locale', $locale)->where('slug', $slug);
            });

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->first();
    }

    public function getTranslation(Content $content, string $locale = 'vi'): ?ContentTranslation
    {
        return $content->translations()->where('locale', $locale)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function contentAttributes(array $data, bool $isNew, ?Content $current = null): array
    {
        $status = $data['status'] ?? ($current->status ?? 'draft');
        $publishedAt = $this->resolvePublishedAt($data, $status, $current);

        return [
            'type' => $data['type'] ?? ($current->type ?? 'page'),
            'status' => $status,
            'author_id' => $data['author_id'] ?? ($current->author_id ?? null),
            'parent_id' => $data['parent_id'] ?? ($current->parent_id ?? null),
            'template' => $data['template'] ?? ($current->template ?? null),
            'featured_image' => $data['featured_image'] ?? ($current->featured_image ?? null),
            'sort_order' => $data['sort_order'] ?? ($current->sort_order ?? 0),
            'comment_status' => $data['comment_status'] ?? ($current->comment_status ?? 'closed'),
            'views_count' => (int) ($data['views_count'] ?? ($current->views_count ?? 0)),
            'comments_count' => (int) ($data['comments_count'] ?? ($current->comments_count ?? 0)),
            'is_featured' => (bool) ($data['is_featured'] ?? ($current->is_featured ?? false)),
            // Page-title visibility invariant (PB-FREE-LIBRARY-DESIGN-1-E-H1).
            // Base-entity presentation flag, default true for backward
            // compatibility; only overwritten when the form actually submits it,
            // so an update that omits it preserves the stored value.
            'show_page_title' => (bool) ($data['show_page_title'] ?? ($current->show_page_title ?? true)),
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePublishedAt(array $data, string $status, ?Content $current): ?CarbonInterface
    {
        if (array_key_exists('published_at', $data) && $data['published_at'] !== null) {
            return $data['published_at'] instanceof DateTimeInterface
                ? Carbon::instance($data['published_at'])
                : Carbon::parse((string) $data['published_at']);
        }

        if ($status === 'published') {
            if ($current && $current->published_at !== null) {
                return $current->published_at;
            }

            return Carbon::now();
        }

        return $current->published_at ?? null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertTranslation(Content $content, array $data, bool $isNew): ContentTranslation
    {
        $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());
        $existing = $isNew
            ? null
            : $content->translations()->where('locale', $locale)->first();

        $title = (string) ($data['title'] ?? $existing?->title ?? '');

        $rawSlug = (string) ($data['slug'] ?? '');
        $baseSlug = $rawSlug !== '' ? $rawSlug : ($existing->slug ?? '');

        if ($baseSlug === '') {
            $baseSlug = $this->slugManager->generate($title, $locale);
        } else {
            $baseSlug = $this->slugManager->generate($baseSlug, $locale);
        }

        // Global per-locale uniqueness across pages/posts/categories/tags
        // (cms_slugs is the source of truth), ignoring this content's own row.
        $uniqueSlug = $this->slugManager->uniquePublicSlug(
            $baseSlug,
            $locale,
            referenceType: 'content',
            referenceId: $content->id,
        );

        $rawContent = $data['content'] ?? $existing?->content;

        $payload = [
            'locale' => $locale,
            'title' => $title !== '' ? $title : null,
            'slug' => $uniqueSlug,
            'excerpt' => $data['excerpt'] ?? $existing?->excerpt,
            // Rich-editor (TinyMCE) HTML is sanitized on every write so the
            // stored body is always safe to render. Only the body is sanitized;
            // title/excerpt/meta are plain text handled elsewhere.
            'content' => $rawContent !== null ? $this->sanitizer->sanitize($rawContent) : null,
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
            'meta_keywords' => $data['meta_keywords'] ?? $existing?->meta_keywords,
        ];

        return $this->persistTranslation($content, $locale, $payload, $existing);
    }

    /**
     * Persist the (already slug-generated, sanitized and normalized) translation
     * payload. For posts, when `translation.modules.posts.write_driver = adapter`,
     * the persistence is delegated to the Phase 9.0D write adapter; for pages, when
     * `translation.modules.pages.write_driver = adapter`, to the Phase 9.1D write
     * adapter (both write through the content driver, joining THIS transaction).
     * Otherwise the legacy Eloquent write is used. Every other type always takes
     * the legacy path. This is the ONLY step the adapters change — slug
     * reservation, sanitization, hooks and cache behaviour above/around it are
     * untouched.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistTranslation(Content $content, string $locale, array $payload, ?ContentTranslation $existing): ContentTranslation
    {
        if ($adapter = $this->postsWriteAdapter($content)) {
            return $adapter->persist($content, $locale, $payload, $existing);
        }

        if ($adapter = $this->pagesWriteAdapter($content)) {
            return $adapter->persist($content, $locale, $payload, $existing);
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        return $content->translations()->create($payload);
    }

    /**
     * The Posts write adapter (Phase 9.0D), or null when it must not be used.
     *
     * Returns the adapter ONLY for posts when `write_driver` is `adapter` — so
     * pages, terms, and the default configuration keep the unchanged legacy write.
     * Fully guarded: any resolution problem falls back to legacy.
     */
    private function postsWriteAdapter(Content $content): ?\TheNguyen\CMS\Translation\Adapters\PostTranslationWriteAdapter
    {
        if ($content->type !== 'post') {
            return null;
        }

        try {
            $adapter = app('cms.translation.posts_write_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Pages write adapter (Phase 9.1D), or null when it must not be used.
     *
     * Returns the adapter ONLY for pages when `write_driver` is `adapter` — so
     * posts, terms, and the default configuration keep the unchanged legacy write.
     * Fully guarded: any resolution problem falls back to legacy.
     */
    private function pagesWriteAdapter(Content $content): ?\TheNguyen\CMS\Translation\Adapters\PageTranslationWriteAdapter
    {
        if ($content->type !== 'page') {
            return null;
        }

        try {
            $adapter = app('cms.translation.pages_write_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int>|null  $termIds
     */
    private function syncTerms(Content $content, ?array $termIds): void
    {
        if ($termIds === null) {
            return;
        }

        $content->terms()->sync(array_values(array_unique(array_map('intval', $termIds))));
    }

    private function upsertSlug(Content $content, ContentTranslation $translation): void
    {
        // Posts get the configurable post base (default "blog"); pages have none.
        $prefix = app('cms.permalink')->contentBase($content->type);

        $fullPath = $this->slugManager->makeFullPath($translation->slug, $prefix);

        Slug::query()->updateOrCreate(
            [
                'reference_type' => 'content',
                'reference_id' => $content->id,
                'locale' => $translation->locale,
            ],
            [
                'slug' => $translation->slug,
                'prefix' => $prefix,
                'full_path' => $fullPath,
                'is_primary' => true,
            ],
        );
    }

    /**
     * Record an immutable, locale-scoped revision for a Post (Phase 9.0F) or Page
     * (Phase 9.1F) write.
     *
     * Gated per entity type: posts by config('revisions.entities.posts'), pages by
     * config('revisions.entities.pages'). The platform master switch
     * config('revisions.enabled') still governs the recorder, so with either the
     * master or the per-entity flag off nothing is recorded and behaviour is
     * unchanged. Any other content type is never recorded. Called inside the write
     * transaction so a rollback removes the revision atomically; retention pruning
     * is deferred to after commit. Fault-isolated — a revision failure is reported
     * but never breaks the content write. ContentManager remains the sole write
     * authority; the revision layer only observes.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordRevision(Content $content, string $locale, array $data): void
    {
        // Per-type entity flag: posts (9.0F) and pages (9.1F) are the wired types;
        // any other content type is never recorded.
        $entityKey = match ($content->type) {
            'post' => 'posts',
            'page' => 'pages',
            default => null,
        };

        if ($entityKey === null) {
            return;
        }

        if (! (bool) config('revisions.entities.'.$entityKey, false)) {
            return;
        }

        try {
            $recorder = app('cms.revision.recorder');
            $recorder->record($content, $locale, $this->revisionContext($data));
            $recorder->pruneAfterCommit($content, $locale);
        } catch (\Throwable $e) {
            // Fault-isolated: revision recording must never break a content write.
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function revisionContext(array $data): RevisionContext
    {
        $authorId = $data['author_id'] ?? (auth()->check() ? auth()->id() : null);

        return $authorId !== null && $authorId !== ''
            ? RevisionContext::admin((int) $authorId)
            : RevisionContext::default();
    }
}
