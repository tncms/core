<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SITEMAP-R1 Gate G: a neutral fixture with the shape of the Knowledge
 * Library (authors, works, books, characters; `status = published`; soft
 * deletes; one slug per record shared by every locale; characters keyed per
 * work) expressed through {@see \TheNguyen\CMS\Sitemap\KeysetSitemapProvider}.
 * No Knowledge Library code or route is used or invented.
 */
final class LibraryShapedProviderTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const SLUG = 'sm-shelf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();

        foreach (['authors', 'works', 'books', 'characters'] as $table) {
            Schema::create('smk_'.$table, static function (Blueprint $t) use ($table): void {
                $t->id();
                $t->string('slug');
                $t->string('status')->default('draft');
                if ($table === 'characters') {
                    $t->unsignedBigInteger('work_id');
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }

        $this->smPlugin(self::SLUG);
        $this->writeShelfProvider();
        $this->smRoutes(self::SLUG);
    }

    private function writeShelfProvider(): void
    {
        $ns = $this->smNamespace(self::SLUG);
        $file = $this->smRoot().DIRECTORY_SEPARATOR.self::SLUG.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'Shelf.php';

        File::put($file, <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use TheNguyen\CMS\Sitemap\KeysetSitemapProvider;
use TheNguyen\CMS\Sitemap\SitemapEntry;

final class ShelfRecord extends Model
{
    use SoftDeletes;

    protected \$guarded = [];
}

/** Library-shaped adapter: published rows only, one slug for every locale. */
final class Shelf extends KeysetSitemapProvider
{
    public static bool \$withCharacters = false;

    public function types(): array
    {
        return self::\$withCharacters ? ['authors', 'books', 'characters', 'works'] : ['authors', 'books', 'works'];
    }

    protected function query(string \$type, string \$locale): ?Builder
    {
        if (! in_array(\$type, \$this->types(), true)) {
            return null;
        }

        \$query = (new ShelfRecord)->setTable('smk_'.\$type)->newQuery()->where('smk_'.\$type.'.status', 'published');

        return \$type === 'characters'
            ? \$query->select(['smk_characters.id', 'smk_characters.slug', 'smk_characters.updated_at', 'smk_works.slug as work_slug'])
                ->join('smk_works', 'smk_works.id', '=', 'smk_characters.work_id')
                ->where('smk_works.status', 'published')
                ->whereNull('smk_works.deleted_at')
            : \$query->select(['id', 'slug', 'updated_at']);
    }

    protected function entriesFor(Collection \$rows, string \$type, string \$locale): iterable
    {
        foreach (\$rows as \$row) {
            \$item = \$type === 'characters' ? \$row->work_slug.'~'.\$row->slug : \$row->slug;
            \$alternates = [];
            foreach (app('cms.language')->getPublicLocales() as \$code) {
                \$alternates[\$code] = \$code === app('cms.language')->defaultCode()
                    ? route('sm-shelf.show', ['type' => \$type, 'item' => \$item])
                    : route('sm-shelf.show.localized', ['locale' => \$code, 'type' => \$type, 'item' => \$item]);
            }

            yield new SitemapEntry((int) \$row->getKey(), \$alternates[\$locale], \$row->updated_at, \$alternates);
        }
    }
}

PHP);

        require_once $file;
    }

    private function shelf(): object
    {
        $class = $this->smNamespace(self::SLUG).'\\Shelf';

        return new $class;
    }

    private function seedShelf(string $table, array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('smk_'.$table)->insert($row + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_published_library_records_are_listed_per_type_and_locale(): void
    {
        $this->seedShelf('authors', [
            ['slug' => 'nguyen-du', 'status' => 'published'],
            ['slug' => 'draft-author', 'status' => 'draft'],
            ['slug' => 'trashed', 'status' => 'published', 'deleted_at' => now()],
            ['slug' => 'ho-xuan-huong', 'status' => 'published'],
        ]);
        $this->seedShelf('works', [['slug' => 'truyen-kieu', 'status' => 'published']]);
        $this->seedShelf('books', [['slug' => 'kieu-1820', 'status' => 'published'], ['slug' => 'unpublished', 'status' => 'review']]);
        $this->seedShelf('characters', [['slug' => 'thuy-kieu', 'status' => 'published', 'work_id' => 1]]);
        $this->assertTrue($this->smCode(self::SLUG)->registerSitemap($this->shelf()));

        $names = array_values(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'sm-shelf-')));
        $this->assertSame([
            'sm-shelf-authors-vi-1', 'sm-shelf-authors-en-1',
            'sm-shelf-books-vi-1', 'sm-shelf-books-en-1',
            'sm-shelf-works-vi-1', 'sm-shelf-works-en-1',
        ], $names, 'characters are not declared: like the Knowledge Library, they have no public route');

        $this->assertSame(
            ['http://localhost/lib-sm-shelf/authors/nguyen-du', 'http://localhost/lib-sm-shelf/authors/ho-xuan-huong'],
            $this->chunkLocs('sm-shelf-authors-vi-1'),
        );
        $this->assertSame(['http://localhost/en/lib-sm-shelf/books/kieu-1820'], $this->chunkLocs('sm-shelf-books-en-1'));
    }

    public function test_composite_keyed_records_are_representable_once_a_route_exists(): void
    {
        $this->seedShelf('works', [['slug' => 'truyen-kieu', 'status' => 'published'], ['slug' => 'draft-work', 'status' => 'draft']]);
        $this->seedShelf('characters', [
            ['slug' => 'thuy-kieu', 'status' => 'published', 'work_id' => 1],
            ['slug' => 'hidden-parent', 'status' => 'published', 'work_id' => 2],
        ]);
        $this->shelf()::$withCharacters = true;

        try {
            $this->assertTrue($this->smCode(self::SLUG)->registerSitemap($this->shelf()));
            $this->assertSame(['http://localhost/lib-sm-shelf/characters/truyen-kieu~thuy-kieu'], $this->chunkLocs('sm-shelf-characters-vi-1'));
        } finally {
            $this->shelf()::$withCharacters = false;
        }
    }

    public function test_large_library_sets_are_chunked_with_bounded_queries(): void
    {
        config(['cms.sitemap.chunk_size' => 500]);
        $rows = [];
        for ($i = 1; $i <= 1200; $i++) {
            $rows[] = ['slug' => 'author-'.$i, 'status' => $i % 10 === 0 ? 'draft' : 'published', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('smk_authors')->insert($chunk);
        }
        $this->assertTrue($this->smCode(self::SLUG)->registerSitemap($this->shelf()));

        $parts = array_values(array_filter($this->indexNames(), static fn ($n) => str_starts_with($n, 'sm-shelf-authors-vi-')));
        $this->assertCount(3, $parts, '1,080 published / 500');

        $all = [];
        foreach ($parts as $name) {
            $queries = 0;
            DB::listen(static function () use (&$queries): void {
                $queries++;
            });
            array_push($all, ...$this->chunkLocs($name));
            app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);
            $this->assertLessThanOrEqual(12, $queries, $name);
        }

        $this->assertCount(1080, $all);
        $this->assertSame(count($all), count(array_unique($all)));
    }
}
