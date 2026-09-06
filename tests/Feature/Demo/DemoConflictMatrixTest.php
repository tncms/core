<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\DemoCategoryImporter;
use TheNguyen\CMS\Services\DemoSymbolResolver;
use TheNguyen\CMS\Services\TaxonomyManager;
use TheNguyen\CMS\Support\DemoConflict;

/**
 * EG-9 Phase 5A — conflict-classification closure. The certified RUNTIME set is
 * the classes the importers actually emit through the supported contract; every
 * one has direct executable evidence somewhere in the Demo suite. This test pins
 * the previously-untested distinction between a DEFAULT-locale slug collision
 * (UNOWNED_SAME_SLUG) and a NON-DEFAULT locale one (UNOWNED_SAME_TRANSLATED_SLUG),
 * and documents that UNOWNED_SAME_TITLE / SYMBOL_MISSING are documentary-only.
 */
final class DemoConflictMatrixTest extends TestCase
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

    public function test_default_locale_collision_is_unowned_same_slug(): void
    {
        // User owns the demo category's DEFAULT-locale slug.
        app(TaxonomyManager::class)->createTerm('category', ['locale' => $this->default, 'name' => 'U', 'slug' => 'tin-tuc']);

        [, , , , $conflicts] = $this->importer->import($this->fixture(), new DemoSymbolResolver, []);

        $this->assertContains(DemoConflict::UNOWNED_SAME_SLUG, array_column($conflicts, 'class'));
        $this->assertNotContains(DemoConflict::UNOWNED_SAME_TRANSLATED_SLUG, array_column($conflicts, 'class'));
    }

    public function test_non_default_locale_collision_is_unowned_same_translated_slug(): void
    {
        // User owns only the demo category's NON-DEFAULT (en) slug; the default
        // (vi) slug is free.
        app(TaxonomyManager::class)->createTerm('category', ['locale' => 'en', 'name' => 'U', 'slug' => 'news']);

        [, , , , $conflicts] = $this->importer->import($this->fixture(), new DemoSymbolResolver, []);

        $classes = array_column($conflicts, 'class');
        $this->assertContains(DemoConflict::UNOWNED_SAME_TRANSLATED_SLUG, $classes);
        $this->assertNotContains(DemoConflict::UNOWNED_SAME_SLUG, $classes);
    }

    public function test_runtime_set_excludes_documentary_only_classes(): void
    {
        $this->assertNotContains(DemoConflict::UNOWNED_SAME_TITLE, DemoConflict::RUNTIME);
        $this->assertNotContains(DemoConflict::SYMBOL_MISSING, DemoConflict::RUNTIME);

        // …but they remain named vocabulary in ALL.
        $this->assertContains(DemoConflict::UNOWNED_SAME_TITLE, DemoConflict::ALL);
        $this->assertContains(DemoConflict::SYMBOL_MISSING, DemoConflict::ALL);

        // The RUNTIME set is exactly the eight emitted classes.
        $this->assertSame([
            DemoConflict::OWNED_MATCH,
            DemoConflict::OWNED_CHANGED,
            DemoConflict::UNOWNED_SAME_SLUG,
            DemoConflict::UNOWNED_SAME_TRANSLATED_SLUG,
            DemoConflict::SYMBOL_DUPLICATE,
            DemoConflict::AUTHOR_UNRESOLVED,
            DemoConflict::MEDIA_UNRESOLVED,
            DemoConflict::TAXONOMY_UNRESOLVED,
        ], DemoConflict::RUNTIME);
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return ['categories' => [[
            'key' => 'news',
            'translations' => [
                'en' => ['name' => 'News', 'slug' => 'news'],
                'vi' => ['name' => 'Tin tức', 'slug' => 'tin-tuc'],
            ],
        ]]];
    }
}
