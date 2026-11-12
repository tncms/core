<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Directory;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Taxonomy\Directory\TermSearchCriteria;

/**
 * CORE-P16-1: additive (taxonomy_id, sort_order, id) index on cms_terms that lets the directory's
 * ordered page scan stop after one page. Idempotent up(), down() drops only its own index, data
 * untouched, and the directory's page query is served by it.
 */
final class TermsDirectoryIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'packages/thenguyen/cms-core/database/migrations/2026_11_05_000001_add_directory_order_index_to_cms_terms.php';

    private const INDEX = 'cms_terms_taxonomy_sort_id_index';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }

    public function test_index_exists_after_migrate_and_up_is_idempotent(): void
    {
        $this->assertTrue(Schema::hasIndex('cms_terms', self::INDEX));
        $this->assertTrue(Schema::hasIndex('cms_terms', ['taxonomy_id', 'sort_order', 'id']));

        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('cms_terms', self::INDEX));
    }

    public function test_down_drops_only_its_index_and_keeps_data(): void
    {
        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $term = app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'Giữ lại']);

        $this->migration()->down();
        $this->assertFalse(Schema::hasIndex('cms_terms', self::INDEX));
        $this->assertTrue(Schema::hasIndex('cms_terms', ['taxonomy_id']), 'pre-existing taxonomy_id index untouched');
        $this->migration()->down();

        $this->migration()->up();
        $this->assertTrue(Schema::hasIndex('cms_terms', self::INDEX));
        $this->assertSame('Giữ lại', $term->refresh()->localeName('vi'));
    }

    public function test_directory_page_query_is_served_by_the_index_on_sqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite query-plan check; MySQL is measured in docs 11 (EXPLAIN ANALYZE).');
        }

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'A']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app('cms.taxonomy.directory')->search(new TermSearchCriteria('category', 'vi'));
        $page = collect(DB::getQueryLog())->first(fn ($q) => str_starts_with(strtolower(str_replace(['"', '`'], '', $q['query'])), 'select cms_terms.* from cms_terms'));
        DB::disableQueryLog();

        $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$page['query'], $page['bindings']))->pluck('detail')->implode(' | ');
        $this->assertStringContainsString(self::INDEX, $plan);
        $this->assertStringNotContainsString('USE TEMP B-TREE FOR ORDER BY', $plan, 'no sort step: rows come in index order');
    }
}
