<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Models\Content;

/**
 * Section data source (theme-implementation; Phase 9B).
 *
 * Produces the raw repeater value(s) a data-bound section should render based on
 * its `data_source` setting, BEFORE the SectionResolver localizes/coerces them —
 * so the view contract never changes and media/localization flow through the
 * normal pipeline.
 *
 * Modes:
 *   - manual      → returns null; the section keeps its authored field content.
 *   - placeholder → curated, theme-shipped content (the safety net; always
 *                   populated when the theme declares a dataset for the section).
 *   - posts       → real published Content driven by the Editorial Query Builder
 *                   (Phase 9B/9C-B/9C-C). The query is assembled by composable
 *                   steps: resolveSource (latest | categories | tags | featured |
 *                   most_viewed | most_commented | manual | authors | related |
 *                   sticky) → applyIncludes → applyExcludes → applyOrdering →
 *                   applyOffset → applyLimit. Legacy `category_slug` / `tag_slug`
 *                   / `featured_only` settings still resolve. NO fallback: zero
 *                   matches (including an empty required selection, or `related`
 *                   off a post-detail page) render an empty section rather than
 *                   fabricating content.
 *
 * The returned array is keyed by the section's repeater field name(s)
 * (e.g. `posts`, `items`, `main`, `blocks`); each maps to a list of raw item
 * arrays in the same shape an author would enter (localized leaves as
 * `{ vi, en }` maps for placeholder; plain current-locale strings for posts).
 */
class SectionDataProvider
{
    /**
     * Per-request cache of loaded placeholder dataset files, keyed by dataset
     * name, so repeated sections do not re-read the same file from disk.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $datasetCache = [];

    /**
     * Memoized sticky-column probe: false = not yet checked, null = no column,
     * string = the column name. Keeps the schema lookup to once per request.
     */
    private string|null|false $stickyColumn = false;

    /**
     * Section type → which repeater field(s) the `posts` mode fills. Sections not
     * listed here do not support `posts` mode (they render empty in that mode).
     *
     * @var array<string, string>
     */
    private const POSTS_TARGET = [
        'post-grid' => 'posts',
        'trending-list' => 'items',
        // featured-grid is handled specially (lead + rest); category-blocks has
        // no flat list and is placeholder/manual-only.
    ];

    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * Raw repeater values to inject for a section, or null to leave the authored
     * (manual) content untouched.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    public function resolve(string $sectionType, array $settings, ?string $locale = null, ?Content $currentPost = null): ?array
    {
        $mode = is_string($settings['data_source'] ?? null) ? $settings['data_source'] : 'manual';

        try {
            return match ($mode) {
                'manual' => null,
                'placeholder' => $this->placeholder($sectionType),
                'posts' => $this->posts($sectionType, $settings, $locale, $currentPost),
                // Any other mode is not a core data source. Offer it to extensions
                // through the generic `cms.section.data_source` filter (Phase 9E-D):
                // a plugin can resolve it and return the injected repeater map; with
                // no listener the filter returns null and the section keeps its
                // authored content — identical to the previous `default => null`.
                default => $this->resolveExternal($sectionType, $mode, $settings, $locale, $currentPost),
            };
        } catch (\Throwable $e) {
            report($e);

            // Never break rendering over a data source; fall through to authored
            // content rather than 500 the page.
            return null;
        }
    }

    /**
     * Public entry for non-section consumers of the Editorial Query Builder
     * (e.g. dynamic mega menus): run the query for an explicit settings array
     * and return the mapped post items in the same shape as {@see mapPosts}. The
     * limit is clamped to the same safe range; never throws (a failure or empty
     * match yields an empty list rather than breaking the caller).
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    public function resolvePosts(array $settings, ?string $locale = null): array
    {
        $locale ??= current_locale();

        $limit = (int) ($settings['limit'] ?? 6);
        $limit = max(1, min($limit, 24));

        try {
            return $this->queryPosts($settings, $locale, $limit);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * Resolve a non-core data source through the generic `cms.section.data_source`
     * filter. Core never knows what the mode is — an extension listening on the
     * filter may return the injected repeater map (e.g. `['posts' => [...]]`) or
     * pass the null through to keep the authored content. Runs inside resolve()'s
     * try/catch, so a throwing listener degrades to authored content, never a 500.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function resolveExternal(string $sectionType, string $mode, array $settings, ?string $locale, ?Content $currentPost): ?array
    {
        if (! function_exists('apply_filters')) {
            return null;
        }

        $result = apply_filters('cms.section.data_source', null, $sectionType, $mode, $settings, $locale, $currentPost);

        return is_array($result) ? $result : null;
    }

    // ------------------------------------------------------------------
    // Placeholder mode
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function placeholder(string $sectionType): array
    {
        $map = $this->loadThemeFile('config/placeholders.php');
        $dataset = is_string($map[$sectionType] ?? null) ? $map[$sectionType] : null;

        if ($dataset === null) {
            return [];
        }

        $data = $this->datasetCache[$dataset] ??= $this->loadThemeFile('placeholders/'.$dataset.'.php');
        $items = $data[$sectionType] ?? [];

        return is_array($items) ? $items : [];
    }

    /**
     * Safely load a config-returning theme file (array or []), relative to the
     * active theme directory. Never throws.
     *
     * @return array<string, mixed>
     */
    private function loadThemeFile(string $relative): array
    {
        $slug = $this->themes->activeSlug() ?? 'default';
        $file = $this->themes->themePath($slug).DIRECTORY_SEPARATOR.$relative;

        if (! File::exists($file)) {
            return [];
        }

        $data = (static fn (): mixed => require $file)();

        return is_array($data) ? $data : [];
    }

    // ------------------------------------------------------------------
    // Posts mode
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function posts(string $sectionType, array $settings, ?string $locale, ?Content $currentPost = null): array
    {
        $locale ??= current_locale();

        $limit = (int) ($settings['limit'] ?? 6);
        $limit = max(1, min($limit, 24));

        // category-blocks is a GROUPED query (one block per selected category),
        // not a flat post list — `limit` means posts PER category. It has its own
        // resolver path so it is never forced into the flat shape.
        if ($sectionType === 'category-blocks') {
            return ['blocks' => $this->resolveCategoryBlocks($settings, $locale, $limit, $currentPost)];
        }

        $items = $this->queryPosts($settings, $locale, $limit, $currentPost);

        // No fallback: an empty result renders an empty section.
        if ($sectionType === 'featured-grid') {
            if ($items === []) {
                return ['main' => [], 'items' => []];
            }

            return ['main' => [array_shift($items)], 'items' => $items];
        }

        $target = self::POSTS_TARGET[$sectionType] ?? null;

        if ($target === null) {
            // Any future flat-less section with no posts target: render empty
            // rather than fabricate or fall back. (category-blocks is handled
            // earlier by its own grouped resolver.)
            return [];
        }

        return [$target => $items];
    }

    /**
     * Run the Editorial Query Builder and map matches to raw item arrays in the
     * section repeater shape. The query is assembled by composable steps so each
     * concern stays small and testable. No N+1: translations and terms are
     * eager-loaded once and the mapping reads from memory.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function queryPosts(array $settings, string $locale, int $limit, ?Content $currentPost = null): array
    {
        $query = $this->basePostQuery($locale);

        // resolveSource defines the base population. It returns false when the
        // source can yield nothing (a required selection is empty, or `related`
        // has no current post) — rendered as an empty section, no fallback.
        $source = $this->resolveSource($query, $settings, $locale, $currentPost);

        if ($source === false) {
            return [];
        }

        $this->applyIncludes($query, $settings, $locale, $source);
        $this->applyExcludes($query, $settings, $currentPost);
        $this->applyOrdering($query, $settings, $locale, $source);
        $this->applyOffset($query, $settings);
        $this->applyLimit($query, $limit);

        $posts = $query->get();

        // A manual selection preserves the author-picked order (the DB cannot).
        if (($source['manual_ids'] ?? null) !== null) {
            $posts = $this->sortByIdOrder($posts, $source['manual_ids']);
        }

        return $this->mapPosts($posts, $locale);
    }

    /**
     * Build category-blocks output: ONE block per selected category, each with its
     * own per-category post list (`limit` = posts PER category, not total).
     *
     * Grouped output is driven by the selected categories only — other source
     * types are not meaningful for a per-category grouping, so they render nothing
     * (no fallback, consistent with the flat builder). Each block's posts run
     * through the same published-visibility guard ({@see applyVisibility} via
     * {@see basePostQuery}), excludes, ordering and offset as the flat builder, and
     * are mapped with {@see mapPosts} so URL/locale/media handling is identical.
     * Category labels and URLs use the term's locale fallback chain.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, array<string, mixed>>
     */
    private function resolveCategoryBlocks(array $settings, string $locale, int $limit, ?Content $currentPost): array
    {
        if ($this->sourceType($settings) !== 'categories') {
            return [];
        }

        $categoryIds = $this->termIds($settings, 'category', $locale);

        if ($categoryIds === []) {
            return [];
        }

        // Load the selected category terms once (label + URL), keyed by id so the
        // author-picked order is preserved and unknown ids are skipped. No N+1.
        $terms = \TheNguyen\CMS\Models\Term::query()
            ->whereKey($categoryIds)
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', 'category'))
            ->with(['translations', 'taxonomy'])
            ->get()
            ->keyBy(static fn (\TheNguyen\CMS\Models\Term $t): int => (int) $t->getKey());

        $blocks = [];

        foreach ($categoryIds as $id) {
            $term = $terms->get($id);

            if ($term === null) {
                continue;
            }

            $query = $this->basePostQuery($locale);
            $this->whereInTaxonomy($query, 'category', [$id]);
            $this->applyExcludes($query, $settings, $currentPost);
            $this->applyOrdering($query, $settings, $locale, []);
            $this->applyOffset($query, $settings);
            $this->applyLimit($query, $limit);

            $url = term_url($term, $locale);

            $blocks[] = [
                'title' => $this->termLabel($term, $locale, ''),
                'url' => $url === '#' ? '' : $url,
                // Real, localized taxonomy description (already sanitized by the
                // TaxonomyManager). The term's translations are eager-loaded above,
                // so this reads from memory — no extra query, no N+1.
                'description' => $term->translatedDescription($locale),
                'posts' => $this->mapPosts($query->get(), $locale),
            ];
        }

        return $blocks;
    }

    /**
     * The base published-content query shared by the flat and grouped builders:
     * posts only, draft-visibility guarded, requiring a slugged translation in the
     * render locale, with translations + terms eager-loaded (no N+1).
     *
     * @return \Illuminate\Database\Eloquent\Builder<Content>
     */
    private function basePostQuery(string $locale): Builder
    {
        $query = Content::query()->posts();

        $this->applyVisibility($query);

        $query
            ->whereHas('translations', static fn ($q) => $q->where('locale', $locale)->whereNotNull('slug'))
            ->with(['translations', 'terms.translations', 'terms.taxonomy']);

        return $query;
    }

    /**
     * Apply the primary `source_type` population to $query. Returns false when the
     * source is definitively empty; otherwise an array of hints
     * (`manual_ids` => the picked order; `skip_includes` => true for sources that
     * fully define their own set). Latest/most_viewed/most_commented add no filter
     * (ordering handles them). `sticky` is honored only when a column exists.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|false
     */
    private function resolveSource(Builder $query, array $settings, string $locale, ?Content $currentPost): array|false
    {
        $sourceType = $this->sourceType($settings);

        $result = [];

        switch ($sourceType) {
            case 'manual':
                $ids = $this->intList($settings['manual_post_ids'] ?? null);

                if ($ids === []) {
                    return false;
                }

                $query->whereKey($ids);

                return ['manual_ids' => $ids, 'skip_includes' => true];

            case 'authors':
                if ($this->intList($settings['author_ids'] ?? null) === []) {
                    return false;
                }
                break;

            case 'categories':
                if ($this->termIds($settings, 'category', $locale) === []) {
                    return false;
                }
                break;

            case 'tags':
                if ($this->termIds($settings, 'tag', $locale) === []) {
                    return false;
                }
                break;

            case 'featured':
                $query->featured();
                break;

            case 'sticky':
                // Future-proof: filter only when a sticky column actually exists,
                // otherwise behave like `latest` (resolver ignores it safely).
                if ($this->stickyColumn() !== null) {
                    $query->where($this->stickyColumn(), true);
                }
                break;

            case 'related':
                return $this->applyRelated($query, $settings, $currentPost)
                    ? ['skip_includes' => true]
                    : false;

                // latest / most_viewed / most_commented → no base filter here.
        }

        // The legacy `featured_only` flag stacks on any source.
        if ((bool) ($settings['featured_only'] ?? false)) {
            $query->featured();
        }

        return $result;
    }

    /**
     * Apply the include filters (category_ids / tag_ids / author_ids). These
     * narrow the population for the source types that consume them and compose as
     * AND with any other constraints. Skipped for sources that fully define their
     * own set (manual / related).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $source
     */
    private function applyIncludes(Builder $query, array $settings, string $locale, array $source): void
    {
        if (($source['skip_includes'] ?? false) === true) {
            return;
        }

        $categoryIds = $this->termIds($settings, 'category', $locale);

        if ($categoryIds !== []) {
            $this->whereInTaxonomy($query, 'category', $categoryIds);
        }

        $tagIds = $this->termIds($settings, 'tag', $locale);

        if ($tagIds !== []) {
            $this->whereInTaxonomy($query, 'tag', $tagIds);
        }

        $authorIds = $this->intList($settings['author_ids'] ?? null);

        if ($authorIds !== []) {
            $query->whereIn('author_id', $authorIds);
        }
    }

    /**
     * Apply the exclude filters: the current post (exclude_current), explicit post
     * ids (exclude_post_ids), and posts in excluded categories/tags.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     */
    private function applyExcludes(Builder $query, array $settings, ?Content $currentPost): void
    {
        if ((bool) ($settings['exclude_current'] ?? false) && $currentPost !== null) {
            $query->whereKeyNot($currentPost->getKey());
        }

        $excludeIds = $this->intList($settings['exclude_post_ids'] ?? null);

        if ($excludeIds !== []) {
            $query->whereNotIn('cms_contents.id', $excludeIds);
        }

        $excludeCategories = $this->intList($settings['exclude_category_ids'] ?? null);

        if ($excludeCategories !== []) {
            $this->whereNotInTaxonomy($query, 'category', $excludeCategories);
        }

        $excludeTags = $this->intList($settings['exclude_tag_ids'] ?? null);

        if ($excludeTags !== []) {
            $this->whereNotInTaxonomy($query, 'tag', $excludeTags);
        }
    }

    /**
     * Apply ordering. A manual selection keeps its picked order (applied after the
     * fetch). Otherwise: an explicit `order_by` wins; a metric `source_type`
     * provides the order when `order_by` is the default `latest`; else latest.
     *
     * Supported order_by: latest | oldest | random | most_viewed | most_commented
     * | title | menu_order. `random` uses database random ordering.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $source
     */
    private function applyOrdering(Builder $query, array $settings, string $locale, array $source): void
    {
        if (($source['manual_ids'] ?? null) !== null) {
            return;
        }

        $orderBy = $this->stringSetting($settings, 'order_by') ?? 'latest';
        $sourceType = $this->sourceType($settings);
        $metric = ['most_viewed', 'most_commented'];

        // Keep the 9C-B behavior: a metric source orders itself unless the author
        // chose a non-default order_by.
        if ($orderBy === 'latest' && in_array($sourceType, $metric, true)) {
            $orderBy = $sourceType;
        }

        match ($orderBy) {
            'oldest' => $query->orderBy('published_at')->orderBy('id'),
            'random' => $query->inRandomOrder(),
            'most_viewed' => $query->mostViewed(),
            'most_commented' => $query->mostCommented(),
            'title' => $query->orderBy(
                \TheNguyen\CMS\Models\ContentTranslation::query()
                    ->select('title')
                    ->whereColumn('content_id', 'cms_contents.id')
                    ->where('locale', $locale)
                    ->limit(1)
            ),
            'menu_order' => $query->orderBy('sort_order')->orderByDesc('published_at'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Apply the start offset (default 0). Negative/zero offsets are a no-op.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     */
    private function applyOffset(Builder $query, array $settings): void
    {
        $offset = (int) ($settings['offset'] ?? 0);

        if ($offset > 0) {
            $query->offset($offset);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     */
    private function applyLimit(Builder $query, int $limit): void
    {
        $query->limit($limit);
    }

    /**
     * Restrict $query to posts related to the current post per `related_mode`.
     * Returns false (→ empty section) when there is no current post (e.g. the
     * homepage) or the chosen relation has nothing to match on. Never queries
     * from Blade — the current post is threaded in by the SectionResolver.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<string, mixed>  $settings
     */
    private function applyRelated(Builder $query, array $settings, ?Content $currentPost): bool
    {
        if ($currentPost === null) {
            return false;
        }

        $mode = $this->stringSetting($settings, 'related_mode') ?? 'automatic';

        if ($mode === 'current_post_only') {
            $query->whereKey([$currentPost->getKey()]);

            return true;
        }

        // A post is never "related" to itself.
        $query->whereKeyNot($currentPost->getKey());

        $categoryIds = $this->postTermIds($currentPost, 'category');
        $tagIds = $this->postTermIds($currentPost, 'tag');

        switch ($mode) {
            case 'same_category':
                if ($categoryIds === []) {
                    return false;
                }
                $this->whereInTaxonomy($query, 'category', $categoryIds);

                return true;

            case 'same_tags':
                if ($tagIds === []) {
                    return false;
                }
                $this->whereInTaxonomy($query, 'tag', $tagIds);

                return true;

            case 'same_author':
                if ($currentPost->author_id === null) {
                    return false;
                }
                $query->where('author_id', $currentPost->author_id);

                return true;

            case 'automatic':
            default:
                // Posts sharing any category OR tag with the current post.
                if ($categoryIds === [] && $tagIds === []) {
                    return true; // degrade to "latest excluding self"
                }

                $query->where(function ($q) use ($categoryIds, $tagIds): void {
                    if ($categoryIds !== []) {
                        $q->orWhereHas('terms', static fn ($t) => $t
                            ->whereHas('taxonomy', static fn ($x) => $x->where('type', 'category'))
                            ->whereIn('cms_terms.id', $categoryIds));
                    }

                    if ($tagIds !== []) {
                        $q->orWhereHas('terms', static fn ($t) => $t
                            ->whereHas('taxonomy', static fn ($x) => $x->where('type', 'tag'))
                            ->whereIn('cms_terms.id', $tagIds));
                    }
                });

                return true;
        }
    }

    /**
     * Narrow $query to posts having at least one term of $type within $termIds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<int, int>  $termIds
     */
    private function whereInTaxonomy(Builder $query, string $type, array $termIds): void
    {
        $query->whereHas('terms', static fn ($q) => $q
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $type))
            ->whereIn('cms_terms.id', $termIds));
    }

    /**
     * Exclude posts having any term of $type within $termIds.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     * @param  array<int, int>  $termIds
     */
    private function whereNotInTaxonomy(Builder $query, string $type, array $termIds): void
    {
        $query->whereDoesntHave('terms', static fn ($q) => $q
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $type))
            ->whereIn('cms_terms.id', $termIds));
    }

    /**
     * The current post's term ids for a taxonomy type (used by `related`).
     *
     * @return array<int, int>
     */
    private function postTermIds(Content $post, string $type): array
    {
        return $post->terms()
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $type))
            ->pluck('cms_terms.id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Draft-visibility guard for public, data-driven sections (Phase 9C-D1).
     *
     * Restricts the population to published content — the SAME check the frontend
     * detail route enforces (`status = published`), so every post a section lists
     * also resolves on its own URL (no draft → 404 mismatch). SoftDeletes already
     * excludes trashed rows, so draft / private / scheduled / pending / trashed
     * are all kept out.
     *
     * The section's `published_only` setting is INTENTIONALLY ignored here:
     * `published_only=false` must never make non-published content public. There
     * is no frontend preview route yet, so authorized draft *preview* (for
     * super-admins / users with a view-draft capability) is deferred to
     * Phase 9C-D2 — enabling it now would surface links that 404 for everyone.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Content>  $query
     */
    private function applyVisibility(Builder $query): void
    {
        $query->published();
    }

    /**
     * The sticky column name when the schema actually has one, else null
     * (future-proof; the resolver ignores `sticky` safely until a column exists).
     */
    private function stickyColumn(): ?string
    {
        if ($this->stickyColumn === false) {
            $this->stickyColumn = Schema::hasColumn('cms_contents', 'is_sticky') ? 'is_sticky' : null;
        }

        return $this->stickyColumn;
    }

    /**
     * Reorder a fetched collection to match an explicit list of ids (manual
     * selection); ids not present are skipped.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Content>  $posts
     * @param  array<int, int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Content>
     */
    private function sortByIdOrder(Collection $posts, array $ids): Collection
    {
        $byId = $posts->keyBy('id');

        $ordered = new Collection;

        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered->push($byId->get($id));
            }
        }

        return $ordered;
    }

    /**
     * Map fetched posts to raw repeater item arrays (current-locale strings).
     * Posts with no resolvable URL in this locale are skipped.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Content>  $posts
     * @return array<int, array<string, mixed>>
     */
    private function mapPosts(Collection $posts, string $locale): array
    {
        $items = [];

        foreach ($posts as $post) {
            $url = content_url($post, $locale);

            if ($url === '#') {
                continue;
            }

            $items[] = [
                'title' => $post->translatedTitle($locale),
                'excerpt' => $this->excerptFor($post, $locale),
                'url' => $url,
                'date' => $post->published_at?->format('Y-m-d') ?? '',
                'category' => $this->categoryLabel($post, $locale),
                'image' => ($post->featured_image !== null && $post->featured_image !== '') ? $post->featured_image : null,
            ];
        }

        return $items;
    }

    /**
     * The effective query-builder source. An explicit, valid `source_type` is
     * used as-is; otherwise it is derived from legacy slug settings (category_slug
     * → categories, tag_slug → tags) so pre-9C-B layouts keep working.
     *
     * @param  array<string, mixed>  $settings
     */
    private function sourceType(array $settings): string
    {
        $allowed = ['latest', 'categories', 'tags', 'featured', 'most_viewed', 'most_commented', 'manual', 'authors', 'related', 'sticky'];
        $explicit = $settings['source_type'] ?? null;

        if (is_string($explicit) && in_array($explicit, $allowed, true)) {
            return $explicit;
        }

        if ($this->stringSetting($settings, 'category_slug') !== null) {
            return 'categories';
        }

        if ($this->stringSetting($settings, 'tag_slug') !== null) {
            return 'tags';
        }

        return 'latest';
    }

    /**
     * The term ids to filter by for a category/tag source. Prefers the authored
     * id list; falls back to mapping a legacy slug (category_slug / tag_slug) to
     * its id so old layouts resolve transparently.
     *
     * @param  array<string, mixed>  $settings
     * @return array<int, int>
     */
    private function termIds(array $settings, string $taxonomyType, string $locale): array
    {
        $key = $taxonomyType === 'tag' ? 'tag_ids' : 'category_ids';
        $ids = $this->intList($settings[$key] ?? null);

        if ($ids !== []) {
            return $ids;
        }

        $slug = $this->stringSetting($settings, $taxonomyType === 'tag' ? 'tag_slug' : 'category_slug');

        if ($slug === null) {
            return [];
        }

        $id = $this->termIdBySlug($taxonomyType, $slug, $locale);

        return $id !== null ? [$id] : [];
    }

    /**
     * Resolve a taxonomy term id from a localized slug, or null when no term in
     * that taxonomy has that slug in the given locale (legacy backward-compat).
     */
    private function termIdBySlug(string $taxonomyType, string $slug, string $locale): ?int
    {
        $id = \TheNguyen\CMS\Models\Term::query()
            ->whereHas('taxonomy', static fn ($t) => $t->where('type', $taxonomyType))
            ->whereHas('translations', static fn ($t) => $t->where('locale', $locale)->where('slug', $slug))
            ->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Normalize a stored multi-select value into a clean list of positive int
     * ids (tolerating numeric strings from form state), de-duplicated.
     *
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $id = (int) $item;

                if ($id > 0) {
                    $out[] = $id;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function stringSetting(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The post's excerpt for the current locale, with fallback chain:
     * current locale → default locale → first available → ''.
     */
    private function excerptFor(Content $post, string $locale): string
    {
        $translations = $post->relationLoaded('translations')
            ? $post->translations
            : $post->translations()->get();

        $excerptIn = static fn (string $code): ?string => is_string($v = $translations->firstWhere('locale', $code)?->excerpt) && $v !== '' ? $v : null;

        $value = $excerptIn($locale);

        if ($value === null) {
            $default = app('cms.language')->defaultCode();
            $value = $default !== $locale ? $excerptIn($default) : null;
        }

        if ($value === null) {
            $first = $translations->first();
            $value = is_string($first?->excerpt) ? $first->excerpt : '';
        }

        return (string) $value;
    }

    /**
     * The localized name of the post's first category term, with fallback chain:
     * current locale → default locale → first available → '' (no term).
     */
    private function categoryLabel(Content $post, string $locale): string
    {
        if (! $post->relationLoaded('terms')) {
            return '';
        }

        foreach ($post->terms as $term) {
            if (($term->taxonomy->type ?? null) === 'category') {
                return $this->termLabel($term, $locale, '');
            }
        }

        return '';
    }

    /**
     * Resolve a term's display name with the fallback chain
     * current locale → default locale → first available → $empty.
     */
    private function termLabel(\TheNguyen\CMS\Models\Term $term, string $locale, string $empty): string
    {
        $name = $term->localeName($locale);

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $default = app('cms.language')->defaultCode();

        if ($default !== $locale) {
            $name = $term->localeName($default);

            if (is_string($name) && $name !== '') {
                return $name;
            }
        }

        if ($term->relationLoaded('translations')) {
            $first = $term->translations->first();

            if (is_string($first?->name) && $first->name !== '') {
                return $first->name;
            }
        }

        return $empty;
    }
}
