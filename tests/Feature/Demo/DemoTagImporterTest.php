<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\DemoSymbolResolver;
use TheNguyen\CMS\Services\DemoTagImporter;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Support\DemoConflict;

/**
 * EG-9 Phase 1C — native tag importer. Tags are Core {@see Term} rows under the
 * flat `tag` taxonomy, written through {@see TaxonomyManager}. No parent, no
 * featured image (flat). Symbolic keys are identity; a user slug collision is
 * never overwritten or claimed.
 */
final class DemoTagImporterTest extends TestCase
{
    use RefreshDatabase;

    private DemoTagImporter $importer;

    private string $default;

    protected function setUp(): void
    {
        parent::setUp();

        app(TaxonomyManager::class)->ensureCoreTaxonomies();

        $this->importer = app(DemoTagImporter::class);
        $this->default = app('cms.language')->defaultCode();
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return [
            'tags' => [
                [
                    'key' => 'tncms',
                    'translations' => [
                        'en' => ['name' => 'TN CMS', 'slug' => 'tncms'],
                        'vi' => ['name' => 'TN CMS', 'slug' => 'tncms-vi'],
                    ],
                ],
            ],
        ];
    }

    public function test_fresh_import_creates_tag_with_vi_en_translations_and_symbolic_identity(): void
    {
        $resolver = new DemoSymbolResolver;

        [$keyMap, $termIds, $fingerprints, $warnings, $conflicts, $any] =
            $this->importer->import($this->fixture(), $resolver, []);

        $this->assertTrue($any);
        $this->assertArrayHasKey('tag:tncms', $keyMap);
        $this->assertCount(1, $termIds);

        $tag = Term::query()->with(['translations', 'taxonomy'])->findOrFail($keyMap['tag:tncms']);
        $this->assertSame('tag', $tag->taxonomy->type);
        $this->assertCount(2, $tag->translations);
        $this->assertSame('TN CMS', $tag->localeName('en'));
        $this->assertNull($tag->parent_id, 'flat taxonomy stores no parent');

        $this->assertSame($keyMap['tag:tncms'], $resolver->idFor('tag', 'tncms'));
        $this->assertArrayHasKey('tag:tncms', $fingerprints);
    }

    public function test_same_input_retry_creates_no_duplicates(): void
    {
        $r1 = new DemoSymbolResolver;
        [$first] = $this->importer->import($this->fixture(), $r1, []);
        $count = Term::query()->count();

        $r2 = new DemoSymbolResolver;
        [$second] = $this->importer->import($this->fixture(), $r2, $first);

        $this->assertSame($first['tag:tncms'], $second['tag:tncms']);
        $this->assertSame($count, Term::query()->count(), 'retry must not duplicate tags');
    }

    public function test_user_owned_conflict_is_preserved_and_classified(): void
    {
        $demoDefaultSlug = $this->fixture()['tags'][0]['translations'][$this->default]['slug'];

        $userTag = app(TaxonomyManager::class)->createTerm('tag', [
            'locale' => $this->default,
            'name' => 'User Tag',
            'slug' => $demoDefaultSlug,
        ]);
        $userSlug = $userTag->translatedSlug($this->default);

        $resolver = new DemoSymbolResolver;
        [$keyMap, , , , $conflicts] = $this->importer->import($this->fixture(), $resolver, []);

        $userTag->refresh();
        $this->assertSame('User Tag', $userTag->localeName($this->default));
        $this->assertSame($userSlug, $userTag->translatedSlug($this->default));

        $demoTag = Term::query()->findOrFail($keyMap['tag:tncms']);
        $this->assertNotSame($userTag->id, $demoTag->id);
        $this->assertNotSame($userSlug, $demoTag->translatedSlug($this->default));

        $this->assertContains(DemoConflict::UNOWNED_SAME_SLUG, array_column($conflicts, 'class'));
    }

    public function test_duplicate_symbolic_key_is_classified(): void
    {
        $data = ['tags' => [
            ['key' => 'dup', 'translations' => [$this->default => ['name' => 'One', 'slug' => 'one']]],
            ['key' => 'dup', 'translations' => [$this->default => ['name' => 'Two', 'slug' => 'two']]],
        ]];

        $resolver = new DemoSymbolResolver;
        [$keyMap, $termIds, , , $conflicts] = $this->importer->import($data, $resolver, []);

        $this->assertCount(1, $termIds, 'a duplicate symbolic key never creates a second row');
        $this->assertContains(DemoConflict::SYMBOL_DUPLICATE, array_column($conflicts, 'class'));
    }
}
