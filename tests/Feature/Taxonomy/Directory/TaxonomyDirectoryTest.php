<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Directory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Taxonomy\Directory\InvalidTermDirectoryRequest;
use TheNguyen\CMS\Taxonomy\Directory\TaxonomyDirectory;
use TheNguyen\CMS\Taxonomy\Directory\TermCursor;
use TheNguyen\CMS\Taxonomy\Directory\TermSearchCriteria;
use TheNguyen\CMS\Taxonomy\Directory\TermSummary;

/**
 * CORE-P16-1: the Core taxonomy directory contract (cms.taxonomy.directory).
 * Search, keyset cursor, exact-id lookup, label/locale policy, canonical URLs, bounds, query budget.
 */
final class TaxonomyDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
    }

    private function dir(): TaxonomyDirectory
    {
        return app('cms.taxonomy.directory');
    }

    /** @param array<string, mixed> $extra */
    private function term(string $taxonomy, string $vi, ?string $en = null, array $extra = []): Term
    {
        $term = app('cms.taxonomy')->createTerm($taxonomy, ['locale' => 'vi', 'name' => $vi] + $extra);

        if ($en !== null) {
            app('cms.taxonomy')->updateTerm($term, ['locale' => 'en', 'name' => $en]);
        }

        return $term->refresh();
    }

    /** @return list<int> */
    private function ids(array $items): array
    {
        return array_map(static fn (TermSummary $s): int => $s->id, $items);
    }

    /** @return list<int> every id reachable by following cursors */
    private function walk(TermSearchCriteria $criteria): array
    {
        $ids = [];
        $cursor = null;
        $guard = 0;

        do {
            $page = $this->dir()->search($criteria, $cursor);
            $ids = [...$ids, ...$this->ids($page->items)];
            $cursor = $page->nextCursor;
        } while ($cursor !== null && ++$guard < 1000);

        return $ids;
    }

    public function test_container_binding_and_alias_resolve_the_same_singleton(): void
    {
        $this->assertInstanceOf(TaxonomyDirectory::class, $this->dir());
        $this->assertSame($this->dir(), app(TaxonomyDirectory::class));
    }

    public function test_empty_query_lists_the_taxonomy_in_sort_order_then_id(): void
    {
        $c = $this->term('category', 'Gamma', null, ['sort_order' => 2]);
        $a = $this->term('category', 'Alpha', null, ['sort_order' => 1]);
        $b = $this->term('category', 'Beta', null, ['sort_order' => 1]);
        $this->term('tag', 'Một thẻ');

        $page = $this->dir()->search(new TermSearchCriteria('category', 'vi'));

        $this->assertSame([$a->id, $b->id, $c->id], $this->ids($page->items));
        $this->assertNull($page->nextCursor);
        $this->assertSame('category', $page->items[0]->taxonomy);
    }

    public function test_search_matches_name_substring_case_insensitively_and_slug_accent_insensitively(): void
    {
        $news = $this->term('category', 'Tin tức Thể thao');
        $this->term('category', 'Kinh tế');

        $this->assertSame([$news->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'vi', 'TỨC'))->items));
        $this->assertSame([$news->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'vi', 'the thao'))->items));
        $this->assertSame([$news->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'vi', 'thao'))->items));
        $this->assertSame([], $this->dir()->search(new TermSearchCriteria('category', 'vi', 'không có'))->items);
    }

    public function test_like_wildcards_in_the_query_are_literal(): void
    {
        $pct = $this->term('tag', '100% sạch');
        $this->term('tag', 'Bình thường');
        $this->term('tag', 'a_b');

        $this->assertSame([$pct->id], $this->ids($this->dir()->search(new TermSearchCriteria('tag', 'vi', '%'))->items));
        $this->assertSame([], $this->dir()->search(new TermSearchCriteria('tag', 'vi', '%%%'))->items);
        $this->assertSame([], $this->dir()->search(new TermSearchCriteria('tag', 'vi', '!'))->items);
        $this->assertCount(1, $this->dir()->search(new TermSearchCriteria('tag', 'vi', '_'))->items);
    }

    public function test_query_is_normalized_and_length_capped(): void
    {
        $t = $this->term('tag', 'Hà Nội');

        $page = $this->dir()->search(new TermSearchCriteria('tag', 'vi', "  Hà\t\n  Nội \x00 "));
        $this->assertSame('Hà Nội', $page->normalizedQuery);
        $this->assertSame([$t->id], $this->ids($page->items));

        $long = $this->dir()->search(new TermSearchCriteria('tag', 'vi', str_repeat('ồ', 500)));
        $this->assertSame(TaxonomyDirectory::MAX_QUERY_LENGTH, mb_strlen($long->normalizedQuery));
        $this->assertSame([], $long->items);
    }

    public function test_strict_mode_needs_a_translation_in_the_requested_locale(): void
    {
        $both = $this->term('category', 'Thế giới', 'World');
        $viOnly = $this->term('category', 'Chỉ tiếng Việt');

        $en = $this->dir()->search(new TermSearchCriteria('category', 'en'));
        $this->assertSame([$both->id], $this->ids($en->items));
        $this->assertSame('World', $en->items[0]->label);
        $this->assertFalse($en->items[0]->labelIsFallback);

        $fallback = $this->dir()->search(new TermSearchCriteria('category', 'en', '', requireTranslation: false));
        $this->assertSame([$both->id, $viOnly->id], $this->ids($fallback->items));
        $viRow = $fallback->items[1];
        $this->assertSame('Chỉ tiếng Việt', $viRow->label);
        $this->assertSame('vi', $viRow->labelLocale);
        $this->assertTrue($viRow->labelIsFallback);
        $this->assertFalse($viRow->hasTranslation);
        $this->assertNull($viRow->slug, 'slugs never fall back');
        $this->assertNull($viRow->url, 'URLs never fall back');
    }

    public function test_fallback_search_matches_requested_or_default_locale_names_and_labels_in_the_requested_locale(): void
    {
        $both = $this->term('category', 'Thế giới', 'World');
        $viOnly = $this->term('category', 'Thế hệ');
        $enOnly = app('cms.taxonomy')->createTerm('category', ['locale' => 'en', 'name' => 'Only English']);

        $page = $this->dir()->search(new TermSearchCriteria('category', 'en', 'Thế', requireTranslation: false));
        $this->assertSame([$both->id, $viOnly->id], $this->ids($page->items), 'the default-locale name is searchable too');
        $this->assertSame('World', $page->items[0]->label, 'label still prefers the requested locale');

        $page = $this->dir()->search(new TermSearchCriteria('category', 'en', 'World', requireTranslation: false));
        $this->assertSame([$both->id], $this->ids($page->items));

        $page = $this->dir()->search(new TermSearchCriteria('category', 'vi', 'Only', requireTranslation: false));
        $this->assertSame([], $page->items, 'a third-locale name is never matched');
        $this->assertSame([$enOnly->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'en', 'Only', requireTranslation: false))->items));
    }

    public function test_duplicate_names_are_distinct_rows_with_distinct_slugs(): void
    {
        $one = $this->term('tag', 'Trùng tên');
        $two = $this->term('tag', 'Trùng tên');

        $items = $this->dir()->search(new TermSearchCriteria('tag', 'vi', 'trùng'))->items;
        $this->assertSame([$one->id, $two->id], $this->ids($items));
        $this->assertNotSame($items[0]->slug, $items[1]->slug);
    }

    public function test_soft_deleted_terms_are_invisible_to_search_and_lookup(): void
    {
        $gone = $this->term('category', 'Đã xoá');
        $kept = $this->term('category', 'Còn lại');
        $gone->delete();

        $this->assertSame([$kept->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'vi'))->items));
        $this->assertSame([$gone->id], $this->dir()->lookup([$gone->id], 'vi')->missing);
    }

    public function test_noindex_terms_remain_discoverable_to_editors(): void
    {
        $hidden = $this->term('category', 'Nội bộ', null, ['seo_robots' => 'noindex']);

        $this->assertSame([$hidden->id], $this->ids($this->dir()->search(new TermSearchCriteria('category', 'vi'))->items));
    }

    public function test_limit_is_clamped_and_cursor_walks_every_row_exactly_once(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->term('tag', "Thẻ $i", null, ['sort_order' => $i % 3]);
        }

        $first = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 5));
        $this->assertCount(5, $first->items);
        $this->assertNotNull($first->nextCursor);

        $all = $this->walk(new TermSearchCriteria('tag', 'vi', '', true, 5));
        $expected = Term::query()->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame($expected, $all);
        $this->assertSame(count($all), count(array_unique($all)));

        $this->assertCount(1, $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 0))->items);
        $this->assertCount(13, $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 9999))->items);
    }

    public function test_max_limit_caps_page_size(): void
    {
        for ($i = 0; $i < TaxonomyDirectory::MAX_LIMIT + 3; $i++) {
            $this->term('tag', "T$i");
        }

        $page = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 9999));
        $this->assertCount(TaxonomyDirectory::MAX_LIMIT, $page->items);
        $this->assertNotNull($page->nextCursor);
    }

    public function test_last_page_has_no_cursor_and_exact_multiple_does_not_emit_an_empty_page(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->term('tag', "T$i");
        }

        $first = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 2));
        $second = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 2), $first->nextCursor);
        $this->assertCount(2, $second->items);
        $this->assertNull($second->nextCursor);
    }

    public function test_cursor_rejects_garbage_tampering_and_foreign_scopes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->term('tag', "Thẻ $i", "Tag $i");
        }

        $cursor = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 1))->nextCursor;
        $this->assertIsString($cursor);

        $reason = function (TermSearchCriteria $c, string $token): string {
            try {
                $this->dir()->search($c, $token);
            } catch (InvalidTermDirectoryRequest $e) {
                return $e->reason;
            }

            return 'accepted';
        };
        $vi = new TermSearchCriteria('tag', 'vi', '', true, 1);

        foreach (['x', 'a.b.c', str_repeat('a', 600).'.b', "{$cursor}x", 'eyJ2IjoxfQ.'.explode('.', $cursor)[1]] as $bad) {
            $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR, $reason($vi, $bad), $bad);
        }

        [$payload] = explode('.', $cursor);
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $forged = rtrim(strtr(base64_encode(json_encode(['id' => 1] + $data)), '+/', '-_'), '=').'.'.explode('.', $cursor)[1];
        $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR, $reason($vi, $forged));

        $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR_SCOPE, $reason(new TermSearchCriteria('tag', 'en', '', true, 1), $cursor));
        $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR_SCOPE, $reason(new TermSearchCriteria('category', 'vi', '', true, 1), $cursor));
        $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR_SCOPE, $reason(new TermSearchCriteria('tag', 'vi', 'Thẻ', true, 1), $cursor));
        $this->assertSame(InvalidTermDirectoryRequest::REASON_CURSOR_SCOPE, $reason(new TermSearchCriteria('tag', 'vi', '', false, 1), $cursor));
        $this->assertSame('accepted', $reason(new TermSearchCriteria('tag', 'vi', '', true, 2), $cursor), 'page size is not part of the scope');
        $this->assertSame('accepted', $reason(new TermSearchCriteria('tag', 'vi', "  \t", true, 1), $cursor), 'scope uses the normalized query');
    }

    public function test_cursor_signed_with_another_app_key_is_rejected(): void
    {
        $this->term('tag', 'A');
        $this->term('tag', 'B');
        $cursor = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 1))->nextCursor;

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->expectException(InvalidTermDirectoryRequest::class);
        $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 1), $cursor);
    }

    public function test_cursor_carries_no_offset_and_survives_concurrent_inserts_and_deletes(): void
    {
        $terms = [];
        for ($i = 0; $i < 6; $i++) {
            $terms[] = $this->term('tag', "T$i");
        }

        $first = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 3));
        $decoded = TermCursor::decode($first->nextCursor, ['t' => 'tag', 'l' => 'vi', 'r' => 1, 'q' => substr(hash('sha256', ''), 0, 32)]);
        $this->assertSame(['so' => 0, 'id' => $terms[2]->id], $decoded);

        // Between pages: a row before the cursor and a row after it are deleted, a new row is added.
        $terms[0]->delete();
        $terms[4]->delete();
        $new = $this->term('tag', 'Mới');

        $second = $this->dir()->search(new TermSearchCriteria('tag', 'vi', '', true, 3), $first->nextCursor);
        $this->assertSame([$terms[3]->id, $terms[5]->id, $new->id], $this->ids($second->items), 'no skipped or repeated surviving row');
    }

    public function test_unsupported_taxonomy_and_locale_are_rejected(): void
    {
        foreach ([['product_cat', 'vi', InvalidTermDirectoryRequest::REASON_TAXONOMY], ['category', 'fr', InvalidTermDirectoryRequest::REASON_LOCALE], ['category', '', InvalidTermDirectoryRequest::REASON_LOCALE]] as [$tax, $loc, $why]) {
            try {
                $this->dir()->search(new TermSearchCriteria($tax, $loc));
                $this->fail("accepted $tax/$loc");
            } catch (InvalidTermDirectoryRequest $e) {
                $this->assertSame($why, $e->reason);
            }
        }
    }

    public function test_inactive_language_is_rejected(): void
    {
        DB::table('cms_languages')->where('code', 'en')->update(['is_active' => false]);
        app()->forgetInstance('cms.localization.config');
        app()->forgetInstance('cms.language');
        app()->forgetInstance('cms.localization.content_url');
        app()->forgetInstance('cms.taxonomy.directory');

        $this->expectException(InvalidTermDirectoryRequest::class);
        $this->dir()->search(new TermSearchCriteria('category', 'en'));
    }

    public function test_locale_input_is_normalized(): void
    {
        $t = $this->term('category', 'Thế giới', 'World');

        $page = $this->dir()->search(new TermSearchCriteria('category', 'EN'));
        $this->assertSame('en', $page->locale);
        $this->assertSame([$t->id], $this->ids($page->items));
    }

    public function test_lookup_dedupes_keeps_request_order_and_buckets_every_input(): void
    {
        $a = $this->term('category', 'A', 'A en');
        $b = $this->term('category', 'B');
        $tag = $this->term('tag', 'Thẻ');
        $gone = $this->term('category', 'Xoá');
        $gone->delete();

        $result = $this->dir()->lookup([(string) $b->id, $a->id, $b->id, 'abc', -3, 0, '01', 999999, $gone->id, $tag->id, null, 1.5], 'en', 'category');

        $this->assertSame([$b->id, $a->id], $result->foundIds());
        $this->assertSame([999999, $gone->id, $tag->id], $result->missing, 'wrong-taxonomy ids are missing, never substituted');
        $this->assertSame(['abc', '-3', '0', '01', 'null', '1.5'], $result->invalid);
        $this->assertSame('A en', $result->get($a->id)->label);
        $this->assertSame('B', $result->get($b->id)->label, 'lookup labels fall back so saved selections stay readable');
        $this->assertTrue($result->get($b->id)->labelIsFallback);
        $this->assertNull($result->get(12345));
    }

    public function test_lookup_without_taxonomy_spans_supported_taxonomies(): void
    {
        $c = $this->term('category', 'C');
        $t = $this->term('tag', 'T');

        $this->assertSame([$t->id, $c->id], $this->dir()->lookup([$t->id, $c->id], 'vi')->foundIds());
        $this->assertSame('tag', $this->dir()->find($t->id, 'vi')->taxonomy);
        $this->assertNull($this->dir()->find($t->id, 'vi', 'category'));
    }

    public function test_lookup_rejects_oversize_batches_but_counts_distinct_ids(): void
    {
        $this->assertSame([], $this->dir()->lookup(array_fill(0, 500, 7), 'vi')->foundIds());
        $this->assertSame([], $this->dir()->lookup([], 'vi')->foundIds());

        $this->expectException(InvalidTermDirectoryRequest::class);
        $this->dir()->lookup(range(1, TaxonomyDirectory::MAX_LOOKUP_IDS + 1), 'vi');
    }

    public function test_parent_id_only_for_hierarchical_taxonomies(): void
    {
        $parent = $this->term('category', 'Cha');
        $child = $this->term('category', 'Con', null, ['parent_id' => $parent->id]);

        $this->assertSame($parent->id, $this->dir()->find($child->id, 'vi')->parentId);
        $this->assertNull($this->dir()->find($this->term('tag', 'T')->id, 'vi')->parentId);
    }

    public function test_urls_are_canonical_root_relative_and_locale_prefixed(): void
    {
        $t = $this->term('category', 'Thế giới', 'World');

        $vi = $this->dir()->find($t->id, 'vi');
        $en = $this->dir()->find($t->id, 'en');

        $this->assertSame(term_url($t, 'vi'), $vi->url, 'same authority as the term_url() helper');
        $this->assertSame(term_url($t, 'en'), $en->url);
        $this->assertStringStartsWith('/', $vi->url);
        $this->assertStringNotContainsString('://', $vi->url);
        $this->assertStringContainsString($vi->slug, $vi->url);
        $this->assertStringStartsWith('/en/', $en->url);
        $this->assertStringContainsString($en->slug, $en->url);
        $this->assertSame($vi->url, $this->dir()->archiveUrl($t->id, 'vi'));
    }

    public function test_absolute_url_uses_configured_origin_not_request_host(): void
    {
        config(['app.url' => 'https://canonical.test']);
        $t = $this->term('tag', 'Thẻ');
        $path = $this->dir()->archiveUrl($t->id, 'vi');

        $this->get('/', ['Host' => 'evil.example']);
        request()->headers->set('Host', 'evil.example');

        $this->assertSame('https://canonical.test'.$path, $this->dir()->archiveUrl($t->id, 'vi', absolute: true));

        config(['app.url' => '']);
        $this->assertNull($this->dir()->archiveUrl($t->id, 'vi', absolute: true));
        $this->assertNull($this->dir()->archiveUrl(999999, 'vi', absolute: true));
    }

    public function test_labels_are_returned_as_plain_data_and_never_html_decoded(): void
    {
        $t = $this->term('tag', '<script>alert(1)</script> &amp; x');

        $label = $this->dir()->find($t->id, 'vi')->label;
        $this->assertSame((string) $t->localeName('vi'), $label, 'the directory returns the stored name verbatim; renderers escape');
        $this->assertSame(e($label), htmlspecialchars($label, ENT_QUOTES, 'UTF-8', true));
    }

    public function test_results_do_not_depend_on_the_actor(): void
    {
        $this->term('category', 'Một');
        $this->term('category', 'Hai', 'Two');

        $guest = $this->dir()->search(new TermSearchCriteria('category', 'vi'))->toArray();
        $this->actingAs(User::factory()->create());
        $member = $this->dir()->search(new TermSearchCriteria('category', 'vi'))->toArray();
        config(['cms.super_admin_emails' => [auth()->user()->email]]);
        $admin = $this->dir()->search(new TermSearchCriteria('category', 'vi'))->toArray();

        $this->assertSame($guest, $member);
        $this->assertSame($guest, $admin);
    }

    public function test_order_by_has_an_id_tie_breaker_on_every_engine(): void
    {
        $this->term('tag', 'A');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->dir()->search(new TermSearchCriteria('tag', 'vi'));
        $sql = collect(DB::getQueryLog())->map(fn ($q) => strtolower(str_replace(['"', '`'], '', $q['query'])))
            ->first(fn (string $q) => str_starts_with($q, 'select cms_terms.* from cms_terms'));
        DB::disableQueryLog();

        $this->assertStringContainsString('order by cms_terms.sort_order asc, cms_terms.id asc limit 21', $sql);
    }

    public function test_query_count_is_constant_regardless_of_dataset_and_page_size(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->term('category', "Mục $i", "Item $i");
        }

        $count = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        // Warm caches that are not the directory's (settings, language list).
        $this->dir()->search(new TermSearchCriteria('category', 'en', '', true, 1));

        $small = $count(fn () => $this->dir()->search(new TermSearchCriteria('category', 'en', '', true, 2)));
        $large = $count(fn () => $this->dir()->search(new TermSearchCriteria('category', 'en', 'item', true, 30)));
        $allIds = Term::query()->pluck('id')->all();
        $lookup = $count(fn () => $this->dir()->lookup($allIds, 'en'));

        $this->assertSame($small, $large, 'no N+1: page size does not change the query count');
        $this->assertLessThanOrEqual(6, $large);
        $this->assertLessThanOrEqual(6, $lookup);
    }
}
