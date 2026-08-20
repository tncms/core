<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use TheNguyen\CMS\Models\Slug;
use TheNguyen\CMS\Models\Taxonomy;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Models\TermTranslation;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;

class TaxonomyManager
{
    public function __construct(
        private readonly SlugManager $slugManager,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function ensureCoreTaxonomies(): void
    {
        if (! Schema::hasTable('cms_taxonomies')) {
            return;
        }

        foreach ($this->coreTaxonomyDefinitions() as $definition) {
            Taxonomy::query()->updateOrCreate(
                [
                    'content_type' => $definition['content_type'],
                    'slug' => $definition['slug'],
                ],
                [
                    'type' => $definition['type'],
                    'hierarchical' => $definition['hierarchical'],
                    'is_core' => true,
                    'sort_order' => $definition['sort_order'],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTerm(string $taxonomySlug, array $data): Term
    {
        $term = DB::transaction(function () use ($taxonomySlug, $data): Term {
            $taxonomy = $this->resolveTaxonomy($taxonomySlug);

            // Hierarchy is taxonomy-driven: a flat taxonomy never stores a parent
            // or a featured image, no matter what the caller passes.
            $parentId = $this->normalizeParentId($taxonomy, $data, term: null);

            $term = Term::query()->create([
                'taxonomy_id' => $taxonomy->id,
                'parent_id' => $parentId,
                'featured_image' => $this->normalizeFeaturedImage($taxonomy, $data, null),
                'sort_order' => $data['sort_order'] ?? 0,
                'count' => 0,
            ]);

            // Hook point: a term is being saved (v1.0.0-beta.7.1.11).
            do_action('cms.term.saving', $term, $data, hook_context(['term' => $term, 'data' => $data]));

            $translation = $this->upsertTermTranslation($term, $data, isNew: true);
            $this->upsertTermSlug($term, $taxonomy, $translation);
            $this->recordRevision($term, $translation->locale, $data);

            return $term->refresh();
        });

        do_action('cms.term.saved', $term, $data, hook_context(['term' => $term, 'data' => $data]));

        return $term;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTerm(Term $term, array $data): Term
    {
        // Hook point: a term is being saved (v1.0.0-beta.7.1.11).
        do_action('cms.term.saving', $term, $data, hook_context(['term' => $term, 'data' => $data]));

        $term = DB::transaction(function () use ($term, $data): Term {
            $taxonomy = $term->taxonomy()->firstOrFail();

            if (array_key_exists('parent_id', $data)) {
                $term->parent_id = $this->normalizeParentId($taxonomy, $data, $term);
            }

            if (array_key_exists('featured_image', $data)) {
                $term->featured_image = $this->normalizeFeaturedImage($taxonomy, $data, $term->featured_image);
            }

            if (array_key_exists('sort_order', $data)) {
                $term->sort_order = (int) $data['sort_order'];
            }

            $term->save();

            $translation = $this->upsertTermTranslation($term, $data, isNew: false);
            $this->upsertTermSlug($term, $taxonomy, $translation);
            $this->recordRevision($term, $translation->locale, $data);

            return $term->refresh();
        });

        do_action('cms.term.saved', $term, $data, hook_context(['term' => $term, 'data' => $data]));

        return $term;
    }

    public function deleteTerm(Term $term): bool
    {
        return DB::transaction(function () use ($term): bool {
            Slug::query()
                ->where('reference_type', 'term')
                ->where('reference_id', $term->id)
                ->delete();

            return (bool) $term->delete();
        });
    }

    public function findTermBySlug(
        string $slug,
        string $locale = 'vi',
        ?string $taxonomySlug = null,
    ): ?Term {
        $query = Term::query()
            ->whereHas('translations', function ($q) use ($slug, $locale): void {
                $q->where('locale', $locale)->where('slug', $slug);
            });

        if ($taxonomySlug !== null) {
            $query->whereHas('taxonomy', function ($q) use ($taxonomySlug): void {
                $q->where('slug', $taxonomySlug);
            });
        }

        return $query->first();
    }

    private function resolveTaxonomy(string $taxonomySlug): Taxonomy
    {
        return Taxonomy::query()
            ->where('slug', $taxonomySlug)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertTermTranslation(Term $term, array $data, bool $isNew): TermTranslation
    {
        $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());
        $existing = $isNew
            ? null
            : $term->translations()->where('locale', $locale)->first();

        $name = (string) ($data['name'] ?? $existing?->name ?? '');

        $rawSlug = (string) ($data['slug'] ?? '');
        $baseSlug = $rawSlug !== '' ? $rawSlug : ($existing->slug ?? '');

        if ($baseSlug === '') {
            $baseSlug = $this->slugManager->generate($name !== '' ? $name : 'term', $locale);
        } else {
            $baseSlug = $this->slugManager->generate($baseSlug, $locale);
        }

        // Global per-locale uniqueness across pages/posts/categories/tags
        // (cms_slugs is the source of truth), ignoring this term's own row.
        $uniqueSlug = $this->slugManager->uniquePublicSlug(
            $baseSlug,
            $locale,
            referenceType: 'term',
            referenceId: $term->id,
        );

        // The description is now rich-editor HTML. Sanitize on every write so the
        // stored value is always safe to render (same guard ContentManager uses
        // for post bodies). A null/absent description leaves the existing value.
        $rawDescription = $data['description'] ?? null;
        $description = $rawDescription !== null
            ? $this->sanitizer->sanitize((string) $rawDescription)
            : $existing?->description;

        $payload = [
            'locale' => $locale,
            'name' => $name !== '' ? $name : ($existing->name ?? 'Untitled'),
            'slug' => $uniqueSlug,
            'description' => $description,
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
        ];

        return $this->persistTermTranslation($term, $locale, $payload, $existing);
    }

    /**
     * Persist ONE already-normalized term translation row — the write persistence
     * tail (Phase 9.2E). It routes through the Taxonomy Write Adapter (Translation
     * Platform v1.0) when `translation.modules.terms.write_driver = adapter`, else
     * keeps the legacy Eloquent write. Either path runs inside the SAME open
     * `DB::transaction` as slug synchronization, so a failure here rolls back the
     * whole term write. This method owns NO slug generation, sanitization,
     * hierarchy or cache concern — those already ran in the caller.
     *
     * @param  array<string, mixed>  $payload
     */
    private function persistTermTranslation(Term $term, string $locale, array $payload, ?TermTranslation $existing): TermTranslation
    {
        if ($adapter = $this->termsWriteAdapter()) {
            return $adapter->persist($term, $locale, $payload, $existing);
        }

        if ($existing) {
            $existing->fill($payload);
            $existing->save();

            return $existing;
        }

        return $term->translations()->create($payload);
    }

    /**
     * Resolve the Taxonomy Write Adapter only when the per-module flag has cut
     * taxonomy translation writes over to the platform; otherwise null (legacy).
     * ONE adapter serves EVERY taxonomy — it never branches on a concrete type. A
     * container/resolution failure falls back to legacy so a misconfiguration can
     * never break a term save.
     */
    private function termsWriteAdapter(): ?\TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationWriteContract
    {
        try {
            /** @var \TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationWriteContract $adapter */
            $adapter = app('cms.translation.terms_write_adapter');

            return $adapter->isActive() ? $adapter : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function upsertTermSlug(Term $term, Taxonomy $taxonomy, TermTranslation $translation): void
    {
        // Category/tag use the configurable bases (defaults "category"/"tag");
        // other taxonomies keep their own slug as the prefix.
        $prefix = match ($taxonomy->type) {
            'category', 'tag' => app('cms.permalink')->termBase($taxonomy->type),
            default => $taxonomy->slug,
        };

        $fullPath = $this->slugManager->makeFullPath($translation->slug, $prefix);

        Slug::query()->updateOrCreate(
            [
                'reference_type' => 'term',
                'reference_id' => $term->id,
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
     * Record an immutable, locale-scoped revision of a term's LOCALIZED content
     * (Phase 9.2G) — reusing the Phase 9.0A Revision Platform exactly as Posts
     * (9.0F) / Pages (9.1F) do.
     *
     * Gated by config('revisions.entities.taxonomy'); the platform master switch
     * config('revisions.enabled') still governs the recorder, so with either flag
     * off nothing is recorded and behaviour is unchanged. Called inside the write
     * transaction (AFTER translation + slug persistence) so a rollback removes the
     * revision atomically; retention pruning is deferred to after commit.
     *
     * The snapshot captures ONLY localized fields (name/slug/description/SEO via
     * Term::snapshotForLocale) — NEVER parent_id, hierarchy, ordering, count or
     * relationships. Fault-isolated: a revision failure is reported but never breaks
     * the term write. TaxonomyManager remains the sole write authority; the revision
     * layer only observes. Works for EVERY taxonomy with no concrete-type branching.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordRevision(Term $term, string $locale, array $data): void
    {
        if (! (bool) config('revisions.entities.taxonomy', false)) {
            return;
        }

        try {
            $recorder = app('cms.revision.recorder');
            $recorder->record($term, $locale, $this->revisionContext($data));
            $recorder->pruneAfterCommit($term, $locale);
        } catch (\Throwable $e) {
            // Fault-isolated: revision recording must never break a term write.
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

    /**
     * Resolve the parent_id to persist, enforcing hierarchy integrity.
     *
     * Flat taxonomies always store null. For hierarchical taxonomies the chosen
     * parent is validated (see assertValidParent) so a self-parent, cross-
     * taxonomy parent, missing/soft-deleted parent, or an ancestor loop can
     * never be written — whether the call comes from the admin UI, an importer,
     * or a plugin.
     *
     * @param  array<string, mixed>  $data
     */
    private function normalizeParentId(Taxonomy $taxonomy, array $data, ?Term $term): ?int
    {
        if (! $taxonomy->isHierarchical()) {
            return null;
        }

        $raw = $data['parent_id'] ?? null;
        $parentId = ($raw === null || $raw === '' || (int) $raw === 0) ? null : (int) $raw;

        $this->assertValidParent($taxonomy, $parentId, $term);

        return $parentId;
    }

    /**
     * Resolve the featured image to persist. Only hierarchical taxonomies carry
     * one; flat taxonomies always store null. An absent key keeps the current
     * value (so partial updates never wipe the image).
     *
     * @param  array<string, mixed>  $data
     */
    private function normalizeFeaturedImage(Taxonomy $taxonomy, array $data, ?string $current): ?string
    {
        if (! $taxonomy->isHierarchical()) {
            return null;
        }

        if (! array_key_exists('featured_image', $data)) {
            return $current;
        }

        $value = $data['featured_image'];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Guard every parent assignment. Throws a ValidationException (surfaced
     * inline by Filament) on any invalid parent:
     *   - self-parenting
     *   - a parent that does not exist or has been soft-deleted
     *   - a parent in a different taxonomy
     *   - a parent that is one of this term's own descendants (loop)
     */
    private function assertValidParent(Taxonomy $taxonomy, ?int $parentId, ?Term $term): void
    {
        if ($parentId === null) {
            return;
        }

        if ($term !== null && $parentId === $term->id) {
            $this->failParent('A term cannot be its own parent.');
        }

        // find() respects SoftDeletes, so a trashed parent resolves to null.
        $parent = Term::query()->find($parentId);

        if ($parent === null) {
            $this->failParent('The selected parent term does not exist.');
        }

        if ((int) $parent->taxonomy_id !== (int) $taxonomy->id) {
            $this->failParent('The parent term must belong to the same taxonomy.');
        }

        if ($term !== null && in_array($parentId, $term->descendantIds(), true)) {
            $this->failParent('A term cannot be moved under one of its own descendants.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function failParent(string $message): never
    {
        throw ValidationException::withMessages(['parent_id' => $message]);
    }

    /**
     * Terms of a taxonomy in depth-first tree order, each tagged with its depth
     * (0 = root). Drives the admin's indented tree list and parent selector
     * without any JS tree or recursive SQL.
     *
     * Pass $excludeId to omit a term AND its whole subtree — used when building
     * the parent options for an existing term (you may never pick yourself or a
     * descendant). Terms whose parent is missing/soft-deleted are kept as roots
     * so the list never silently loses rows.
     *
     * @return list<array{term: Term, depth: int}>
     */
    public function treeOrderedTerms(int $taxonomyId, ?int $excludeId = null): array
    {
        $terms = Term::query()
            ->where('taxonomy_id', $taxonomyId)
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        /** @var array<int, list<Term>> $byParent */
        $byParent = [];
        $ids = [];

        foreach ($terms as $term) {
            $byParent[(int) ($term->parent_id ?? 0)][] = $term;
            $ids[(int) $term->id] = true;
        }

        $ordered = [];
        $visited = [];

        $walk = function (int $parentKey, int $depth) use (&$walk, &$ordered, &$visited, $byParent, $excludeId): void {
            foreach ($byParent[$parentKey] ?? [] as $term) {
                $id = (int) $term->id;

                if ($excludeId !== null && $id === $excludeId) {
                    continue; // skips the node and (by not recursing) its subtree
                }

                $ordered[] = ['term' => $term, 'depth' => $depth];
                $visited[$id] = true;
                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        // Re-home orphans (parent missing/soft-deleted) as roots so nothing is
        // dropped. Their own subtrees are picked up by the same pass.
        foreach ($terms as $term) {
            $id = (int) $term->id;

            if (isset($visited[$id]) || ($excludeId !== null && $id === $excludeId)) {
                continue;
            }

            $parentKey = (int) ($term->parent_id ?? 0);

            if ($parentKey !== 0 && ! isset($ids[$parentKey])) {
                $ordered[] = ['term' => $term, 'depth' => 0];
                $visited[$id] = true;
                $walk($id, 1);
            }
        }

        return $ordered;
    }

    /**
     * @return list<array{type: string, content_type: string, slug: string, hierarchical: bool, sort_order: int}>
     */
    private function coreTaxonomyDefinitions(): array
    {
        return [
            [
                'type' => 'category',
                'content_type' => 'post',
                'slug' => 'category',
                'hierarchical' => true,
                'sort_order' => 1,
            ],
            [
                'type' => 'tag',
                'content_type' => 'post',
                'slug' => 'tag',
                'hierarchical' => false,
                'sort_order' => 2,
            ],
        ];
    }
}
