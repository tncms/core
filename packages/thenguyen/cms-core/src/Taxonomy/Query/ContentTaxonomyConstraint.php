<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use TheNguyen\CMS\Models\Content;

/**
 * Public, read-only seam that narrows a caller-owned `Builder<Content>` by category/tag terms (CORE-P17-1).
 *
 * Container binding `cms.taxonomy.content_constraint` (plus class alias). The seam only ever ANDs one taxonomy
 * predicate onto the caller's query. Eligibility (type, status, publication, locale, visibility, ordering,
 * columns, pagination) stays with the caller and is never broadened, even when the caller used an ungrouped
 * `orWhere`. Term ids are always bound parameters. Constraints are subqueries, so `constrain()` issues no
 * query and adds no per-row query; `relatedTo()` issues exactly one existence read.
 *
 * Fail-closed rules:
 *  - an empty selection, or a selection with ANY malformed, missing, soft-deleted or foreign-taxonomy member,
 *    matches nothing (no member is dropped silently);
 *  - more than MAX_TERM_IDS distinct ids, an unsupported taxonomy / match / mode, or an incompatible builder
 *    throws InvalidContentTaxonomyConstraint;
 *  - `relatedTo()` without a live relationship constrains the query to nothing (and returns false), so an
 *    ignored return value can never yield "latest" or unrelated content.
 *
 * The term relationship is locale-free; locale filtering stays with the caller. The legacy
 * `RelatedContentSelector` is unchanged and is not part of this contract.
 *
 * @api
 */
final class ContentTaxonomyConstraint
{
    public const MAX_TERM_IDS = 50;

    public const MATCH_ANY = 'any';

    public const MATCH_ALL = 'all';

    public const MODE_SAME_CATEGORY = 'same_category';

    public const MODE_SAME_TAGS = 'same_tags';

    public const MODE_AUTOMATIC = 'automatic';

    /** @var list<string> */
    public const SUPPORTED_TAXONOMIES = ['category', 'tag'];

    /** @var array<string, list<string>> related mode => taxonomy types it may share */
    private const MODE_TAXONOMIES = [
        self::MODE_SAME_CATEGORY => ['category'],
        self::MODE_SAME_TAGS => ['tag'],
        self::MODE_AUTOMATIC => ['category', 'tag'],
    ];

    private const CANONICAL_ID = '/^[1-9][0-9]{0,17}$/D';

    /**
     * Narrow $query to content carrying ANY (or ALL) of the given live terms of $taxonomy.
     *
     * @param  Builder<Content>  $query
     * @param  iterable<mixed>  $termIds  positive ints or canonical decimal strings
     *
     * @throws InvalidContentTaxonomyConstraint
     */
    public function constrain(Builder $query, string $taxonomy, iterable $termIds, string $match = self::MATCH_ANY): void
    {
        $this->assertCompatible($query);

        if (! in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true)) {
            throw new InvalidContentTaxonomyConstraint(InvalidContentTaxonomyConstraint::REASON_TAXONOMY);
        }

        if ($match !== self::MATCH_ANY && $match !== self::MATCH_ALL) {
            throw new InvalidContentTaxonomyConstraint(InvalidContentTaxonomyConstraint::REASON_MATCH);
        }

        [$ids, $malformed] = self::parseIds($termIds);

        if (count($ids) > self::MAX_TERM_IDS) {
            throw new InvalidContentTaxonomyConstraint(InvalidContentTaxonomyConstraint::REASON_TOO_MANY_TERMS);
        }

        $base = $this->isolate($query);

        if ($malformed || $ids === []) {
            $base->whereRaw('1 = 0');

            return;
        }

        $count = count($ids);

        // Every selected id must be a live term of the requested taxonomy; otherwise the whole selection fails.
        $base->where(
            $base->newQuery()->from('cms_terms')
                ->join('cms_taxonomies', 'cms_taxonomies.id', '=', 'cms_terms.taxonomy_id')
                ->whereIn('cms_terms.id', $ids)
                ->where('cms_taxonomies.type', $taxonomy)
                ->whereNull('cms_terms.deleted_at')
                ->selectRaw('count(*)'),
            '=',
            $count,
        );

        $pivot = $base->newQuery()->from('cms_content_terms')
            ->whereColumn('cms_content_terms.content_id', 'cms_contents.id')
            ->whereIn('cms_content_terms.term_id', $ids);

        if ($match === self::MATCH_ANY) {
            $base->whereExists($pivot->selectRaw('1'));

            return;
        }

        // unique(content_id, term_id) guarantees one pivot row per selected term.
        $base->where($pivot->selectRaw('count(*)'), '=', $count);
    }

    /**
     * Narrow $query to content sharing at least one live term (of the mode's taxonomies) with $current,
     * excluding $current itself. Returns true when a relationship constraint was applied (rows may still be
     * empty); returns false, and constrains $query to nothing, when $current is missing, unsaved, trashed
     * or carries no live term of the mode's taxonomies.
     *
     * @param  Builder<Content>  $query
     *
     * @throws InvalidContentTaxonomyConstraint
     */
    public function relatedTo(Builder $query, Content|int $current, string $mode = self::MODE_AUTOMATIC): bool
    {
        $this->assertCompatible($query);

        $types = self::MODE_TAXONOMIES[$mode] ?? null;

        if ($types === null) {
            throw new InvalidContentTaxonomyConstraint(InvalidContentTaxonomyConstraint::REASON_MODE);
        }

        $key = $current instanceof Content ? ($current->exists ? $current->getKey() : null) : $current;
        $key = is_int($key) || (is_string($key) && preg_match(self::CANONICAL_ID, $key) === 1) ? (int) $key : 0;

        $base = $this->isolate($query);

        if ($key < 1) {
            $base->whereRaw('1 = 0');

            return false;
        }

        $currentTerms = $base->newQuery()->from('cms_content_terms as current_link')
            ->join('cms_terms as current_term', 'current_term.id', '=', 'current_link.term_id')
            ->join('cms_taxonomies as current_taxonomy', 'current_taxonomy.id', '=', 'current_term.taxonomy_id')
            ->join('cms_contents as current_content', 'current_content.id', '=', 'current_link.content_id')
            ->where('current_link.content_id', $key)
            ->whereNull('current_term.deleted_at')
            ->whereNull('current_content.deleted_at')
            ->whereIn('current_taxonomy.type', $types)
            ->select('current_link.term_id');

        $applied = (clone $currentTerms)->exists();

        $base->where('cms_contents.id', '!=', $key);

        if (! $applied) {
            $base->whereRaw('1 = 0');

            return false;
        }

        $base->whereExists(
            $base->newQuery()->from('cms_content_terms')
                ->whereColumn('cms_content_terms.content_id', 'cms_contents.id')
                ->whereIn('cms_content_terms.term_id', $currentTerms)
                ->selectRaw('1'),
        );

        return true;
    }

    private function assertCompatible(Builder $query): void
    {
        if (! $query->getModel() instanceof Content || $query->getQuery()->from !== 'cms_contents') {
            throw new InvalidContentTaxonomyConstraint(InvalidContentTaxonomyConstraint::REASON_QUERY);
        }
    }

    /**
     * Group the caller's existing top-level wheres when they contain an OR, so what the seam ANDs on narrows
     * the caller's WHOLE condition instead of binding to its last OR branch.
     */
    private function isolate(Builder $query): QueryBuilder
    {
        $base = $query->getQuery();

        $hasOr = false;
        foreach ($base->wheres as $where) {
            if (($where['boolean'] ?? 'and') !== 'and') {
                $hasOr = true;
                break;
            }
        }

        if ($hasOr) {
            $group = $base->forNestedWhere();
            $group->wheres = $base->wheres;
            $group->bindings['where'] = $base->bindings['where'];
            $base->wheres = [];
            $base->bindings['where'] = [];
            $base->addNestedWhereQuery($group);
        }

        return $base;
    }

    /** @return array{0: list<int>, 1: bool} distinct valid ids, and whether any member was malformed */
    private static function parseIds(iterable $ids): array
    {
        $wanted = [];
        $malformed = false;

        foreach ($ids as $raw) {
            $id = match (true) {
                is_int($raw) => $raw,
                is_string($raw) && preg_match(self::CANONICAL_ID, $raw) === 1 => (int) $raw,
                default => 0,
            };

            if ($id < 1) {
                $malformed = true;

                continue;
            }

            $wanted[$id] = $id;
        }

        return [array_values($wanted), $malformed];
    }
}
