<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use TheNguyen\CMS\Localization\Contracts\LanguageConfigurationContract;
use TheNguyen\CMS\Models\Taxonomy;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Seo\CanonicalHost;
use TheNguyen\CMS\Services\LocalizedContentUrlService;
use TheNguyen\CMS\Services\SettingsManager;

/**
 * CORE-P16-1 — the Core taxonomy directory: bounded, read-only, plugin-safe term search and
 * exact-id lookup. Container key `cms.taxonomy.directory` (alias of this class).
 *
 * - Read authority only. {@see \TheNguyen\CMS\Services\TaxonomyManager} stays the only writer.
 * - Supported taxonomies: the ones Core routes and localizes (`category`, `tag`), matched on
 *   cms_taxonomies.type. Anything else is rejected, never guessed.
 * - Visibility: a term is visible when it is not soft-deleted and belongs to a supported
 *   taxonomy. Terms have no status column; their names, slugs and archive paths are public facts,
 *   so results are identical for every actor. Callers that expose the directory over HTTP or
 *   Livewire authorize the request themselves (see docs 05). Per-term noindex does NOT hide a
 *   term here: robots is a search-engine policy, not editor discoverability.
 * - Locale: normalized and required to be an enabled site locale. Labels: requested locale →
 *   default locale → first translation → "Term #id" ({@see TermSummary::$labelIsFallback}).
 *   Slugs and URLs are exact-locale only (no fallback), from the canonical URL service.
 * - Bounds: page size 1..MAX_LIMIT (default DEFAULT_LIMIT), query capped at MAX_QUERY_LENGTH
 *   characters, lookups capped at MAX_LOOKUP_IDS distinct ids. Constant query count per call.
 * - Order: (sort_order, id) ascending — deterministic on every database. Pagination is keyset on
 *   that pair through a signed opaque cursor ({@see TermCursor}); no offsets, no total counts.
 * - Matching: case-insensitive substring of the name in the matched locale, or substring of the
 *   slug against the slugified query (accent-insensitive on every database). LIKE wildcards in the
 *   query are matched literally.
 */
final class TaxonomyDirectory
{
    public const SUPPORTED_TAXONOMIES = ['category', 'tag'];

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const MAX_QUERY_LENGTH = 100;

    public const MAX_LOOKUP_IDS = 100;

    private const LIKE_ESCAPE = '!';

    private const TRANSLATION_COLUMNS = 'translations:id,term_id,locale,name,slug';

    public function __construct(
        private readonly LanguageConfigurationContract $locales,
        private readonly LocalizedContentUrlService $urls,
        private readonly SettingsManager $settings,
    ) {}

    /**
     * One bounded page of terms matching $criteria, after $cursor when given.
     *
     * @throws InvalidTermDirectoryRequest unsupported taxonomy/locale, or a malformed, tampered or
     *                                     foreign cursor (a cursor only continues the exact search that issued it)
     */
    public function search(TermSearchCriteria $criteria, ?string $cursor = null): TermSearchPage
    {
        $taxonomy = $this->taxonomy($criteria->taxonomy);
        $locale = $this->locale($criteria->locale);
        $query = self::normalizeQuery($criteria->query);
        $limit = max(1, min(self::MAX_LIMIT, $criteria->limit));
        $scope = ['t' => $taxonomy, 'l' => $locale, 'r' => $criteria->requireTranslation ? 1 : 0, 'q' => substr(hash('sha256', $query), 0, 32)];
        $after = $cursor === null || $cursor === '' ? null : TermCursor::decode($cursor, $scope);

        $taxa = $this->taxonomies($taxonomy);
        $builder = $this->visibleTerms($taxa);
        $this->applyMatch($builder, $locale, $query, $criteria->requireTranslation);

        if ($after !== null) {
            $builder->where(static function (Builder $w) use ($after): void {
                $w->where('cms_terms.sort_order', '>', $after['so'])
                    ->orWhere(static fn (Builder $x) => $x->where('cms_terms.sort_order', $after['so'])->where('cms_terms.id', '>', $after['id']));
            });
        }

        $rows = self::withTaxonomies($builder->orderBy('cms_terms.sort_order')->orderBy('cms_terms.id')->limit($limit + 1)->get(), $taxa);
        $next = null;

        if ($rows->count() > $limit) {
            $rows = $rows->take($limit);
            $last = $rows->last();
            $next = TermCursor::encode($scope, (int) $last->sort_order, (int) $last->id);
        }

        return new TermSearchPage($this->summaries($rows, $locale), $next, $taxonomy, $locale, $query);
    }

    /**
     * Exact-id batch lookup (selection hydration). Ids are deduplicated in request order; at most
     * MAX_LOOKUP_IDS distinct well-formed ids per call. Never substitutes a name or slug match.
     *
     * @param  iterable<mixed>  $ids  positive integers or their decimal string form
     * @param  string|null  $taxonomy  restrict to one taxonomy (ids of another one are `missing`)
     *
     * @throws InvalidTermDirectoryRequest unsupported taxonomy/locale, or too many distinct ids
     */
    public function lookup(iterable $ids, string $locale, ?string $taxonomy = null): TermLookupResult
    {
        $locale = $this->locale($locale);
        $taxonomy = $taxonomy === null ? null : $this->taxonomy($taxonomy);
        [$wanted, $invalid] = self::parseIds($ids);

        if (count($wanted) > self::MAX_LOOKUP_IDS) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_BATCH);
        }

        if ($wanted === []) {
            return new TermLookupResult([], [], $invalid);
        }

        $taxa = $this->taxonomies($taxonomy);
        $rows = self::withTaxonomies($this->visibleTerms($taxa)->whereIn('cms_terms.id', $wanted)->get(), $taxa);
        $byId = [];

        foreach ($this->summaries($rows, $locale) as $summary) {
            $byId[$summary->id] = $summary;
        }

        $found = [];
        $missing = [];

        foreach ($wanted as $id) {
            if (isset($byId[$id])) {
                $found[$id] = $byId[$id];
            } else {
                $missing[] = $id;
            }
        }

        return new TermLookupResult($found, $missing, $invalid);
    }

    /** One term by exact id, or null when it is missing or not of $taxonomy. */
    public function find(int|string $id, string $locale, ?string $taxonomy = null): ?TermSummary
    {
        $result = $this->lookup([$id], $locale, $taxonomy);

        return array_values($result->found)[0] ?? null;
    }

    /**
     * The canonical public archive URL of a term in $locale, or null (missing term, or no
     * translation in that locale). Root-relative by default. Absolute URLs use the configured
     * APP_URL origin only — never the request host — and are null when APP_URL has no usable origin.
     */
    public function archiveUrl(int|string $id, string $locale, bool $absolute = false): ?string
    {
        $path = $this->find($id, $locale)?->url;

        if ($path === null || ! $absolute) {
            return $path;
        }

        $origin = CanonicalHost::configured()['origin'] ?? null;

        return $origin === null ? null : $origin.$path;
    }

    /** Normalized form of a free-text query: control characters removed, whitespace collapsed, length-capped. */
    public static function normalizeQuery(string $query): string
    {
        $clean = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $query);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        return mb_substr($clean, 0, self::MAX_QUERY_LENGTH);
    }

    /**
     * The requested taxonomy rows keyed by id. One query; it also supplies each term's `taxonomy`
     * relation (see withTaxonomies()).
     *
     * @return Collection<int, Taxonomy>
     */
    private function taxonomies(?string $taxonomy): Collection
    {
        $types = $taxonomy === null ? self::SUPPORTED_TAXONOMIES : [$taxonomy];

        return Taxonomy::query()->whereIn('type', $types)->get(['id', 'type', 'hierarchical'])->keyBy('id');
    }

    /**
     * Visible terms filtered by literal taxonomy ids, so the (taxonomy_id, sort_order, id) index
     * can serve the ordered scan and stop after one page.
     *
     * @param  Collection<int, Taxonomy>  $taxa
     * @return Builder<Term>
     */
    private function visibleTerms(Collection $taxa): Builder
    {
        return Term::query()
            ->select('cms_terms.*')
            ->whereIn('cms_terms.taxonomy_id', $taxa->keys()->all())
            ->with(self::TRANSLATION_COLUMNS);
    }

    /**
     * @param  Collection<int, Term>  $rows
     * @param  Collection<int, Taxonomy>  $taxa
     * @return Collection<int, Term>
     */
    private static function withTaxonomies(Collection $rows, Collection $taxa): Collection
    {
        foreach ($rows as $term) {
            $term->setRelation('taxonomy', $taxa[$term->taxonomy_id] ?? null);
        }

        return $rows;
    }

    private function applyMatch(Builder $builder, string $locale, string $query, bool $requireTranslation): void
    {
        $match = $query === '' ? null : $this->matcher($query);
        $named = static fn (Builder $t, string $code) => $t->where('locale', $code)->where('name', '!=', '');

        if ($requireTranslation) {
            $builder->whereHas('translations', static function (Builder $t) use ($named, $locale, $match): void {
                $named($t, $locale);
                $match && $t->where($match);
            });

            return;
        }

        if ($match === null) {
            return;
        }

        // Fallback mode matches the name in the requested OR the default locale (one EXISTS, so a
        // match terminates early; the label still follows the requested → default → first chain).
        $codes = array_values(array_unique([$locale, $this->locales->defaultLocale()]));

        $builder->whereHas('translations', static fn (Builder $t) => $t
            ->whereIn('locale', $codes)
            ->where('name', '!=', '')
            ->where($match));
    }

    private function matcher(string $query): \Closure
    {
        $escape = static fn (string $v): string => str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $v,
        );
        $name = '%'.$escape(mb_strtolower($query)).'%';
        $slug = Str::slug($query);

        return static function (Builder $t) use ($escape, $name, $slug): void {
            $t->whereRaw('LOWER(cms_term_translations.name) LIKE ? ESCAPE ?', [$name, self::LIKE_ESCAPE]);

            if ($slug !== '') {
                $t->orWhereRaw('cms_term_translations.slug LIKE ? ESCAPE ?', ['%'.$escape($slug).'%', self::LIKE_ESCAPE]);
            }
        };
    }

    /**
     * @param  Collection<int, Term>  $rows
     * @return list<TermSummary>
     */
    private function summaries(Collection $rows, string $locale): array
    {
        $default = $this->locales->defaultLocale();

        // One settings read per key for the whole batch (permalink bases are read per URL).
        return $this->settings->withinReadScope(fn (): array => $rows
            ->map(fn (Term $term): TermSummary => $this->summarize($term, $locale, $default))
            ->values()
            ->all());
    }

    private function summarize(Term $term, string $locale, string $default): TermSummary
    {
        [$label, $labelLocale] = $this->label($term, $locale, $default);
        $hierarchical = (bool) ($term->taxonomy?->hierarchical ?? false);

        return new TermSummary(
            id: (int) $term->id,
            taxonomy: (string) $term->taxonomy?->type,
            label: $label,
            labelLocale: $labelLocale,
            labelIsFallback: $labelLocale !== $locale,
            hasTranslation: $labelLocale === $locale,
            slug: $term->localeSlug($locale),
            url: $this->urls->forResource($term, $locale),
            parentId: $hierarchical && $term->parent_id !== null ? (int) $term->parent_id : null,
        );
    }

    /** @return array{0: string, 1: ?string} */
    private function label(Term $term, string $locale, string $default): array
    {
        foreach (array_unique([$locale, $default]) as $code) {
            $name = $term->localeName($code);

            if ($name !== null && $name !== '') {
                return [$name, $code];
            }
        }

        $first = $term->translations->first(static fn ($t): bool => is_string($t->name) && $t->name !== '');

        return $first === null ? ['Term #'.$term->id, null] : [(string) $first->name, (string) $first->locale];
    }

    private function taxonomy(string $taxonomy): string
    {
        if (! in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true)) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_TAXONOMY);
        }

        return $taxonomy;
    }

    private function locale(string $locale): string
    {
        $code = trim($locale) === '' ? '' : $this->locales->normalize($locale);

        if ($code === '' || ! $this->locales->isEnabled($code)) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_LOCALE);
        }

        return $code;
    }

    /**
     * @param  iterable<mixed>  $ids
     * @return array{0: list<int>, 1: list<string>}
     */
    private static function parseIds(iterable $ids): array
    {
        $wanted = [];
        $invalid = [];

        foreach ($ids as $raw) {
            $id = match (true) {
                is_int($raw) => $raw,
                is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/D', $raw) === 1 => (int) $raw,
                default => 0,
            };

            if ($id < 1) {
                $invalid[] = mb_substr(is_scalar($raw) ? (string) $raw : get_debug_type($raw), 0, 32);

                continue;
            }

            $wanted[$id] = $id;
        }

        return [array_values($wanted), $invalid];
    }
}
