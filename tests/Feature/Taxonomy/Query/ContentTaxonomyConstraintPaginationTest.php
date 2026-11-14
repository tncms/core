<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Taxonomy\Query\ContentTaxonomyConstraint;

/**
 * CORE-P17-1: real pagination and query-cost composition of `cms.taxonomy.content_constraint`.
 *
 * 60 published posts n = 1..60. Category A on n % 2 == 0, category B on n % 3 == 0, tag X on n % 5 == 0.
 * Three posts share each published_at, so the caller's (published_at DESC, id DESC) order needs its id tie-breaker.
 */
final class ContentTaxonomyConstraintPaginationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> n => content id */
    private array $post = [];

    private int $a;

    private int $b;

    private int $x;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $this->a = (int) app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'A'])->getKey();
        $this->b = (int) app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'B'])->getKey();
        $this->x = (int) app('cms.taxonomy')->createTerm('tag', ['locale' => 'vi', 'name' => 'X'])->getKey();

        $base = now()->subDays(100)->startOfDay();

        for ($n = 1; $n <= 60; $n++) {
            $content = Content::query()->create(['type' => 'post', 'status' => 'published', 'published_at' => $base->copy()->addDays(intdiv($n - 1, 3))]);
            $content->terms()->attach(array_values(array_filter([
                $n % 2 === 0 ? $this->a : null,
                $n % 3 === 0 ? $this->b : null,
                $n % 5 === 0 ? $this->x : null,
            ])));
            $this->post[$n] = (int) $content->getKey();
        }
    }

    private function seam(): ContentTaxonomyConstraint
    {
        return app('cms.taxonomy.content_constraint');
    }

    private function callerQuery(): Builder
    {
        return Content::query()->where('type', 'post')->published()->orderByDesc('published_at')->orderByDesc('id');
    }

    /** @param callable(int): bool $predicate @return list<int> expected ids in caller order */
    private function expected(callable $predicate): array
    {
        $ids = [];
        foreach ($this->post as $n => $id) {
            if ($predicate($n)) {
                $ids[] = $id;
            }
        }

        // published_at DESC, id DESC: later groups first and, inside a group, higher ids first == descending id.
        rsort($ids);

        return $ids;
    }

    /** @return list<list<int>> */
    private function pages(string $match, array $termIds, int $perPage, int &$total): array
    {
        $pages = [];
        $page = 1;

        do {
            $q = $this->callerQuery();
            $this->seam()->constrain($q, 'category', $termIds, $match);
            $paginator = $q->paginate($perPage, ['cms_contents.*'], 'page', $page);
            $total = $paginator->total();
            $pages[] = $paginator->getCollection()->pluck('id')->map(static fn ($v): int => (int) $v)->all();
            $page++;
        } while ($paginator->hasMorePages());

        return $pages;
    }

    public function test_any_paginates_over_three_pages_without_overlap_or_duplicates(): void
    {
        $total = 0;
        $pages = $this->pages('any', [$this->a, $this->b], 15, $total);
        $expected = $this->expected(static fn (int $n): bool => $n % 2 === 0 || $n % 3 === 0);

        $this->assertSame(40, $total);
        $this->assertCount(3, $pages);
        $this->assertSame([15, 15, 10], array_map('count', $pages));
        $this->assertSame($expected, array_merge(...$pages), 'stable caller order across pages, no overlap, no gaps');
        $this->assertSame(count($expected), count(array_unique(array_merge(...$pages))));
    }

    public function test_all_paginates_with_the_exact_intersection(): void
    {
        $total = 0;
        $pages = $this->pages('all', [$this->a, $this->b], 4, $total);
        $expected = $this->expected(static fn (int $n): bool => $n % 6 === 0);

        $this->assertSame(10, $total);
        $this->assertSame([4, 4, 2], array_map('count', $pages));
        $this->assertSame($expected, array_merge(...$pages));
    }

    public function test_related_paginates_strictly(): void
    {
        // Current = n 30 (A, B, X). automatic = shares A or B or X, minus itself.
        $expected = array_values(array_diff($this->expected(static fn (int $n): bool => $n % 2 === 0 || $n % 3 === 0 || $n % 5 === 0), [$this->post[30]]));
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $q = $this->callerQuery();
            $this->assertTrue($this->seam()->relatedTo($q, $this->post[30], 'automatic'));
            $paginator = $q->paginate(20, ['cms_contents.*'], 'page', $page);
            $this->assertSame(count($expected), $paginator->total());
            $seen = [...$seen, ...$paginator->getCollection()->pluck('id')->map(static fn ($v): int => (int) $v)->all()];
        }

        $this->assertSame($expected, $seen);
    }

    /**
     * The seam issues no query while constraining, and adds no per-row query: a get() is one statement whatever
     * the number of rows; a paginator is two (count + page) on the first and on a deep page alike.
     */
    public function test_query_count_is_flat_in_the_number_of_rows(): void
    {
        $counts = [];

        foreach ([1, 6, 24] as $limit) {
            foreach ([['any', [$this->a]], ['any', [$this->a, $this->b]], ['all', [$this->a, $this->b]]] as [$match, $ids]) {
                $q = $this->callerQuery()->limit($limit);
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->seam()->constrain($q, 'category', $ids, $match);
                $atCall = count(DB::getQueryLog());
                $rows = $q->get();
                $counts[] = [$limit, $match, $atCall, count(DB::getQueryLog()), $rows->count()];
                DB::disableQueryLog();
            }
        }

        foreach ($counts as [$limit, $match, $atCall, $total, $rows]) {
            $this->assertSame(0, $atCall, "constrain() must not query ({$limit}/{$match})");
            $this->assertSame(1, $total, "one statement for {$rows} rows ({$limit}/{$match})");
        }

        foreach ([1, 3] as $page) {
            $q = $this->callerQuery();
            $this->seam()->constrain($q, 'category', [$this->a, $this->b]);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $q->paginate(15, ['cms_contents.*'], 'page', $page);
            $this->assertCount(2, DB::getQueryLog(), "paginator page {$page}: count + page only");
            DB::disableQueryLog();
        }

        foreach ([[30, 'automatic', true], [30, 'same_tags', true], [1, 'same_tags', false]] as [$n, $mode, $applied]) {
            $q = $this->callerQuery()->limit(24);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->assertSame($applied, $this->seam()->relatedTo($q, $this->post[$n], $mode));
            $atCall = count(DB::getQueryLog());
            $q->get();
            $this->assertSame(1, $atCall, "relatedTo() reads the current relationship once ({$mode})");
            $this->assertCount(2, DB::getQueryLog(), "relatedTo + one result statement ({$mode})");
            DB::disableQueryLog();
        }
    }
}
