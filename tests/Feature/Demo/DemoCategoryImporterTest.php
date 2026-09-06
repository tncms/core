<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\DemoCategoryImporter;
use TheNguyen\CMS\Services\DemoSymbolResolver;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Support\DemoConflict;

/**
 * EG-9 Phase 1B — native category importer. Categories are Core `Term` rows under
 * the hierarchical `category` taxonomy, written through {@see TaxonomyManager}
 * (Core owns every write). Symbolic keys are import identity; parents are
 * symbolic refs; slugs are content data and a user slug collision is NEVER
 * overwritten or claimed.
 */
final class DemoCategoryImporterTest extends TestCase
{
    use RefreshDatabase;

    private DemoCategoryImporter $importer;

    private string $default;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomyManager::class)->ensureCoreTaxonomies();

        $this->importer = app(DemoCategoryImporter::class);
        $this->default = app('cms.language')->defaultCode();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return [
            'categories' => [
                [
                    'key' => 'updates',
                    'sort_order' => 1,
                    'translations' => [
                        'en' => ['name' => 'Updates', 'slug' => 'updates', 'description' => '<p>All updates</p>'],
                        'vi' => ['name' => 'Cập nhật', 'slug' => 'cap-nhat'],
                    ],
                ],
                [
                    'key' => 'news',
                    'parent' => 'category:updates',
                    'sort_order' => 2,
                    'translations' => [
                        'en' => ['name' => 'News', 'slug' => 'news'],
                        'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc'],
                    ],
                ],
            ],
        ];
    }

    public function test_fresh_import_creates_categories_with_vi_en_translations_and_symbolic_identity(): void
    {
        $resolver = new DemoSymbolResolver;

        [$keyMap, $termIds, $fingerprints, $warnings, $conflicts, $any] =
            $this->importer->import($this->fixture(), $resolver, []);

        $this->assertTrue($any);
        $this->assertArrayHasKey('category:updates', $keyMap);
        $this->assertArrayHasKey('category:news', $keyMap);
        $this->assertCount(2, $termIds);

        $news = Term::query()->with('translations')->findOrFail($keyMap['category:news']);
        $this->assertCount(2, $news->translations, 'both locales must import');
        $this->assertSame('News', $news->localeName('en'));
        $this->assertSame('Tin tức', $news->localeName('vi'));

        // Symbolic identity recoverable + registered for later cross-object refs.
        $this->assertSame($keyMap['category:news'], $resolver->idFor('category', 'news'));

        // Deterministic ordering persisted.
        $this->assertSame(2, (int) $news->sort_order);

        // Fingerprint recorded per symbolic key.
        $this->assertArrayHasKey('category:news', $fingerprints);
        $this->assertMatchesRegularExpression('/^sha256:/', $fingerprints['category:news']);
    }

    public function test_hierarchical_parent_symbolic_reference_is_wired(): void
    {
        $resolver = new DemoSymbolResolver;
        [$keyMap] = $this->importer->import($this->fixture(), $resolver, []);

        $news = Term::query()->findOrFail($keyMap['category:news']);
        $this->assertSame($keyMap['category:updates'], (int) $news->parent_id, 'parent symbolic ref must resolve');

        $updates = Term::query()->findOrFail($keyMap['category:updates']);
        $this->assertNull($updates->parent_id, 'root category has no parent');
    }

    public function test_same_input_retry_creates_no_duplicates_and_reuses_rows(): void
    {
        $resolver1 = new DemoSymbolResolver;
        [$first, $ids1] = $this->importer->import($this->fixture(), $resolver1, []);
        $countAfterFirst = Term::query()->count();

        // Retry with the prior imported_keys.
        $resolver2 = new DemoSymbolResolver;
        [$second, $ids2] = $this->importer->import($this->fixture(), $resolver2, $first);

        $this->assertSame($first['category:news'], $second['category:news'], 'retry reuses the same term row');
        $this->assertSame($countAfterFirst, Term::query()->count(), 'retry must not duplicate categories');
    }

    public function test_user_owned_slug_collision_is_not_overwritten_and_is_classified(): void
    {
        // Slug uniqueness is per-locale, so the collision must be in the SAME
        // locale the demo writes: use the demo "news" default-locale slug.
        $demoDefaultSlug = $this->fixture()['categories'][1]['translations'][$this->default]['slug'];

        // A user creates a category occupying that slug BEFORE the demo import.
        $userNews = app(TaxonomyManager::class)->createTerm('category', [
            'locale' => $this->default,
            'name' => 'User News',
            'slug' => $demoDefaultSlug,
        ]);
        $userSlug = $userNews->translatedSlug($this->default);
        $this->assertSame($demoDefaultSlug, $userSlug, 'user owns the colliding slug');

        $resolver = new DemoSymbolResolver;
        [$keyMap, , , , $conflicts] = $this->importer->import($this->fixture(), $resolver, []);

        // The user's term is untouched: same id, same slug, same name.
        $userNews->refresh();
        $this->assertSame('User News', $userNews->localeName($this->default));
        $this->assertSame($userSlug, $userNews->translatedSlug($this->default));

        // The demo's "news" got a distinct row and a distinct (suffixed) slug.
        $demoNews = Term::query()->findOrFail($keyMap['category:news']);
        $this->assertNotSame($userNews->id, $demoNews->id);
        $this->assertNotSame($userSlug, $demoNews->translatedSlug($this->default));

        // …and the collision is CLASSIFIED, never silently swallowed.
        $classes = array_column($conflicts, 'class');
        $this->assertContains(DemoConflict::UNOWNED_SAME_SLUG, $classes);
    }

    public function test_unresolved_parent_reference_is_classified_and_imports_without_parent(): void
    {
        $data = ['categories' => [[
            'key' => 'orphan',
            'parent' => 'category:does-not-exist',
            'translations' => [$this->default => ['name' => 'Orphan', 'slug' => 'orphan']],
        ]]];

        $resolver = new DemoSymbolResolver;
        [$keyMap, , , , $conflicts] = $this->importer->import($data, $resolver, []);

        $orphan = Term::query()->findOrFail($keyMap['category:orphan']);
        $this->assertNull($orphan->parent_id, 'unresolved parent imports without a parent');

        $classes = array_column($conflicts, 'class');
        $this->assertContains(DemoConflict::TAXONOMY_UNRESOLVED, $classes);
    }
}
