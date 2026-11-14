<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Taxonomy\Query\ContentTaxonomyConstraint;
use TheNguyen\CMS\Taxonomy\Query\InvalidContentTaxonomyConstraint;

/**
 * CORE-P17-1: public taxonomy-to-content constraint and strict related-content seam
 * (`cms.taxonomy.content_constraint`). Behavioural contract: exact result sets, never a docblock check.
 *
 * Fixture (all `post`, published unless noted):
 *   p1 A · p2 B · p3 A,B · p4 A,X · p5 X · p6 (no terms) · p7 A (draft) · p8 Cdel
 *   p9 A,B,C · p10 Y,Cdel,Tdel · p11 A (soft-deleted content)
 * A, B, C = categories; Cdel = soft-deleted category; X, Y = tags; Tdel = soft-deleted tag.
 */
final class ContentTaxonomyConstraintContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, int> */
    private array $t = [];

    /** @var array<string, int> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();

        foreach (['A' => 'category', 'B' => 'category', 'C' => 'category', 'Cdel' => 'category', 'X' => 'tag', 'Y' => 'tag', 'Tdel' => 'tag'] as $key => $taxonomy) {
            $this->t[$key] = (int) app('cms.taxonomy')->createTerm($taxonomy, ['locale' => 'vi', 'name' => 'T '.$key])->getKey();
        }

        $fixture = [
            'p1' => ['A'], 'p2' => ['B'], 'p3' => ['A', 'B'], 'p4' => ['A', 'X'], 'p5' => ['X'], 'p6' => [],
            'p7' => ['A'], 'p8' => ['Cdel'], 'p9' => ['A', 'B', 'C'], 'p10' => ['Y', 'Cdel', 'Tdel'], 'p11' => ['A'],
        ];

        $i = 0;
        foreach ($fixture as $key => $terms) {
            $content = Content::query()->create([
                'type' => 'post',
                'status' => $key === 'p7' ? 'draft' : 'published',
                'published_at' => now()->subDays(30 - $i++),
            ]);
            $content->terms()->attach(array_map(fn (string $t): int => $this->t[$t], $terms));
            $this->p[$key] = (int) $content->getKey();
        }

        Content::query()->whereKey($this->p['p11'])->first()->delete();
        Term::query()->whereKey([$this->t['Cdel'], $this->t['Tdel']])->get()->each->delete();
    }

    private function seam(): ContentTaxonomyConstraint
    {
        return app('cms.taxonomy.content_constraint');
    }

    /** A caller-owned, already eligible query: published posts, deterministic order. */
    private function eligible(): Builder
    {
        return Content::query()->where('type', 'post')->published()->orderBy('id');
    }

    /** @return list<string> fixture keys of the query's rows, in result order */
    private function keys(Builder $query): array
    {
        $byId = array_flip($this->p);

        return array_map(static fn ($id): string => $byId[(int) $id] ?? 'unknown:'.$id, $query->pluck('cms_contents.id')->all());
    }

    /** @param list<string|int> $termKeys */
    private function ids(array $termKeys): array
    {
        return array_map(fn ($k) => is_string($k) && isset($this->t[$k]) ? $this->t[$k] : $k, $termKeys);
    }

    // ── binding ─────────────────────────────────────────────────────────────

    public function test_the_binding_and_class_alias_resolve_one_singleton(): void
    {
        $this->assertInstanceOf(ContentTaxonomyConstraint::class, $this->seam());
        $this->assertSame($this->seam(), app(ContentTaxonomyConstraint::class));
    }

    // ── constrain(): any / all ──────────────────────────────────────────────

    public function test_any_matches_content_with_at_least_one_selected_term_once_each(): void
    {
        $q = $this->eligible();
        $this->seam()->constrain($q, 'category', $this->ids(['A', 'B']));

        $this->assertSame(['p1', 'p2', 'p3', 'p4', 'p9'], $this->keys($q));
        $this->assertSame(5, (clone $q)->count(), 'one row per content, never one per matching term');
    }

    public function test_all_matches_content_having_every_selected_term_and_allows_extra_terms(): void
    {
        $q = $this->eligible();
        $this->seam()->constrain($q, 'category', $this->ids(['A', 'B']), ContentTaxonomyConstraint::MATCH_ALL);

        $this->assertSame(['p3', 'p9'], $this->keys($q), 'p9 also has C: "all" is not "exactly"');
    }

    public function test_single_term_and_tag_taxonomy(): void
    {
        $q = $this->eligible();
        $this->seam()->constrain($q, 'tag', $this->ids(['X']));
        $this->assertSame(['p4', 'p5'], $this->keys($q));

        $q = $this->eligible();
        $this->seam()->constrain($q, 'tag', $this->ids(['X', 'Y']), 'all');
        $this->assertSame([], $this->keys($q));
    }

    public function test_duplicates_and_canonical_numeric_strings_normalize_to_the_same_selection(): void
    {
        $a = $this->t['A'];

        foreach ([[$a], [$a, $a], [(string) $a, $a], [(string) $a]] as $selection) {
            $q = $this->eligible();
            $this->seam()->constrain($q, 'category', $selection, 'all');
            $this->assertSame(['p1', 'p3', 'p4', 'p9'], $this->keys($q), json_encode($selection));
        }
    }

    // ── constrain(): empty, invalid, missing, wrong taxonomy ────────────────

    public function test_an_empty_selection_matches_nothing_and_never_everything(): void
    {
        foreach (['any', 'all'] as $match) {
            $q = $this->eligible();
            $this->seam()->constrain($q, 'category', [], $match);
            $this->assertSame([], $this->keys($q), $match);
            $this->assertSame(0, (clone $q)->count());
        }
    }

    /** @return array<string, array{list<mixed>}> */
    public static function poisonedSelections(): array
    {
        return [
            'missing id' => [['A', 999999]],
            'soft-deleted term' => [['A', 'Cdel']],
            'term of another taxonomy' => [['A', 'X']],
            'zero' => [['A', 0]],
            'negative' => [['A', -3]],
            'leading-zero string' => [['A', '01']],
            'non-numeric string' => [['A', 'abc']],
            'float' => [['A', 1.0]],
            'null' => [['A', null]],
            'bool' => [['A', true]],
            'nested array' => [['A', [1]]],
            'only invalid' => [['abc']],
        ];
    }

    /** @dataProvider poisonedSelections */
    #[\PHPUnit\Framework\Attributes\DataProvider('poisonedSelections')]
    public function test_any_invalid_member_makes_the_whole_selection_match_nothing(array $selection): void
    {
        foreach (['any', 'all'] as $match) {
            $q = $this->eligible();
            $this->seam()->constrain($q, 'category', $this->ids($selection), $match);
            $this->assertSame([], $this->keys($q), $match.' must not silently drop the invalid member');
        }
    }

    public function test_more_than_fifty_distinct_ids_is_a_typed_rejection(): void
    {
        $q = $this->eligible();

        try {
            $this->seam()->constrain($q, 'category', range(1, ContentTaxonomyConstraint::MAX_TERM_IDS + 1));
            $this->fail('expected a rejection');
        } catch (InvalidContentTaxonomyConstraint $e) {
            $this->assertSame(InvalidContentTaxonomyConstraint::REASON_TOO_MANY_TERMS, $e->reason);
        }

        // 50 distinct ids (plus duplicates) are within the limit.
        $this->seam()->constrain($this->eligible(), 'category', [...range(1, 50), 1, 2, 3]);
        $this->addToAssertionCount(1);
    }

    public function test_unsupported_taxonomy_and_match_mode_are_typed_rejections(): void
    {
        foreach ([['post_tag', 'any', InvalidContentTaxonomyConstraint::REASON_TAXONOMY], ['category', 'none', InvalidContentTaxonomyConstraint::REASON_MATCH], ['Category', 'any', InvalidContentTaxonomyConstraint::REASON_TAXONOMY]] as [$taxonomy, $match, $reason]) {
            try {
                $this->seam()->constrain($this->eligible(), $taxonomy, [$this->t['A']], $match);
                $this->fail("expected a rejection for {$taxonomy}/{$match}");
            } catch (InvalidContentTaxonomyConstraint $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
    }

    // ── composition: caller predicates are never broadened ──────────────────

    public function test_caller_predicates_are_preserved_and_only_narrowed(): void
    {
        // Plugin-owned eligibility (an id allowlist), publication, and visibility of the type.
        $allow = [$this->p['p1'], $this->p['p2'], $this->p['p7'], $this->p['p9'], $this->p['p11']];
        $q = Content::query()->where('type', 'post')->published()->whereIn('id', $allow)->orderBy('id');
        $this->seam()->constrain($q, 'category', $this->ids(['A']));

        $this->assertSame(['p1', 'p9'], $this->keys($q), 'draft p7, trashed p11 and non-allowlisted rows stay out');
    }

    public function test_an_ungrouped_caller_or_where_is_not_broadened(): void
    {
        // status = published OR id = p7 — the seam must AND with the WHOLE caller condition.
        $q = Content::query()->where('status', 'published')->orWhere('id', $this->p['p7'])->orderBy('id');
        $this->seam()->constrain($q, 'category', $this->ids(['B']));

        $this->assertSame(['p2', 'p3', 'p9'], $this->keys($q));

        $grouped = Content::query()->where(fn (Builder $w) => $w->where('id', $this->p['p5'])->orWhere('id', $this->p['p3']))->orderBy('id');
        $this->seam()->constrain($grouped, 'category', $this->ids(['A']));
        $this->assertSame(['p3'], $this->keys($grouped));
    }

    public function test_locale_predicates_stay_with_the_caller(): void
    {
        DB::table('cms_content_translations')->insert([
            ['content_id' => $this->p['p1'], 'locale' => 'en', 'title' => 'one', 'slug' => 'one', 'created_at' => now(), 'updated_at' => now()],
            ['content_id' => $this->p['p3'], 'locale' => 'vi', 'title' => 'three', 'slug' => 'three', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $q = $this->eligible()->whereHas('translations', static fn ($t) => $t->where('locale', 'en'));
        $this->seam()->constrain($q, 'category', $this->ids(['A']));

        $this->assertSame(['p1'], $this->keys($q), 'the term relationship is locale-free; the caller filters locale');
    }

    public function test_selected_columns_ordering_offset_limit_and_paginator_are_retained(): void
    {
        $q = Content::query()->select(['cms_contents.id', 'cms_contents.type'])->where('type', 'post')->published()
            ->orderByDesc('published_at')->orderByDesc('id')->offset(1)->limit(2);
        $this->seam()->constrain($q, 'category', $this->ids(['A', 'B']));

        $rows = $q->get();
        $this->assertSame([$this->p['p4'], $this->p['p3']], $rows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(['id', 'type'], array_keys($rows->first()->getAttributes()));

        $page = $this->eligible()->tap(fn (Builder $b) => $this->seam()->constrain($b, 'category', $this->ids(['A', 'B'])))->paginate(2, ['*'], 'page', 3);
        $this->assertSame(5, $page->total());
        $this->assertSame([$this->p['p9']], $page->getCollection()->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_incompatible_builders_are_rejected(): void
    {
        foreach ([Term::query(), Content::query()->from('cms_contents as c')] as $query) {
            try {
                $this->seam()->constrain($query, 'category', [$this->t['A']]);
                $this->fail('expected an incompatible-query rejection');
            } catch (InvalidContentTaxonomyConstraint $e) {
                $this->assertSame(InvalidContentTaxonomyConstraint::REASON_QUERY, $e->reason);
            }
        }
    }

    public function test_term_ids_are_bound_parameters_never_sql_text(): void
    {
        $q = $this->eligible();
        $this->seam()->constrain($q, 'category', [$this->t['A'], (string) $this->t['B']], 'all');

        $this->assertStringNotContainsString((string) $this->t['A'], $q->toSql());
        $this->assertContains($this->t['A'], $q->getBindings());
    }

    // ── relatedTo(): strict related content ─────────────────────────────────

    public function test_related_modes_return_exact_sets_and_exclude_the_current_content(): void
    {
        $cases = [
            ['p4', 'same_category', ['p1', 'p3', 'p9']],
            ['p4', 'same_tags', ['p5']],
            ['p4', 'automatic', ['p1', 'p3', 'p5', 'p9']],
            ['p2', 'same_category', ['p3', 'p9']],
            ['p9', 'same_category', ['p1', 'p2', 'p3', 'p4']],
        ];

        foreach ($cases as [$current, $mode, $expected]) {
            foreach ([$this->p[$current], Content::query()->find($this->p[$current])] as $ref) {
                $q = $this->eligible();
                $this->assertTrue($this->seam()->relatedTo($q, $ref, $mode), "{$current}/{$mode}");
                $this->assertSame($expected, $this->keys($q), "{$current}/{$mode}");
            }
        }
    }

    public function test_no_relationship_fails_closed_even_when_the_return_value_is_ignored(): void
    {
        $cases = [
            ['p6', 'automatic'], ['p6', 'same_category'], ['p6', 'same_tags'], // no terms at all
            ['p8', 'same_category'], ['p8', 'automatic'],                       // only a soft-deleted category
            ['p10', 'same_category'],                                           // its only category is soft-deleted
            ['p5', 'same_category'], ['p1', 'same_tags'],                       // no term of the requested kind
        ];

        foreach ($cases as [$current, $mode]) {
            $q = $this->eligible();
            $this->assertFalse($this->seam()->relatedTo($q, $this->p[$current], $mode), "{$current}/{$mode}");
            $this->assertSame([], $this->keys($q), "{$current}/{$mode}: no latest/unrelated substitution");

            $ignored = $this->eligible();
            $this->seam()->relatedTo($ignored, $this->p[$current], $mode); // return value deliberately ignored
            $this->assertCount(0, $ignored->get(), "{$current}/{$mode}: ignored return must still be empty");
        }
    }

    public function test_missing_deleted_unsaved_or_malformed_current_content_fails_closed(): void
    {
        foreach ([999999, 0, -5, $this->p['p11'], new Content(['type' => 'post'])] as $current) {
            $q = $this->eligible();
            $this->assertFalse($this->seam()->relatedTo($q, $current, 'automatic'));
            $this->assertCount(0, $q->get());
        }
    }

    public function test_true_means_a_relationship_constraint_was_applied_not_that_rows_exist(): void
    {
        // p10's only live term is tag Y, which no other content carries.
        $q = $this->eligible();
        $this->assertTrue($this->seam()->relatedTo($q, $this->p['p10'], 'same_tags'));
        $this->assertSame([], $this->keys($q));
    }

    public function test_related_composes_with_caller_predicates(): void
    {
        $q = $this->eligible()->whereIn('id', [$this->p['p1'], $this->p['p5'], $this->p['p7']]);
        $this->assertTrue($this->seam()->relatedTo($q, $this->p['p4'], 'automatic'));
        $this->assertSame(['p1', 'p5'], $this->keys($q), 'draft p7 shares A but the caller excluded it');
    }

    public function test_unsupported_related_mode_is_a_typed_rejection(): void
    {
        foreach (['same_author', 'current_post_only', 'latest', ''] as $mode) {
            try {
                $this->seam()->relatedTo($this->eligible(), $this->p['p4'], $mode);
                $this->fail("expected a rejection for '{$mode}'");
            } catch (InvalidContentTaxonomyConstraint $e) {
                $this->assertSame(InvalidContentTaxonomyConstraint::REASON_MODE, $e->reason);
            }
        }
    }

    public function test_related_rejects_incompatible_builders(): void
    {
        $this->expectException(InvalidContentTaxonomyConstraint::class);
        $this->seam()->relatedTo(Term::query(), $this->p['p4'], 'automatic');
    }
}
