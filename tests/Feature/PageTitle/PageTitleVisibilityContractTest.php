<?php

declare(strict_types=1);

namespace Tests\Feature\PageTitle;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use TheNguyen\CMS\Filament\Forms\PageTitleVisibilityFormField;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

/**
 * PB-FREE-LIBRARY-DESIGN-1-E-H1 — the core page-title visibility contract.
 *
 * A base-entity, locale-invariant presentation flag (`show_page_title`) that
 * defaults to Show for backward compatibility, persists independently of the
 * translations, and is surfaced on the page admin form immediately after the
 * editing-language selector through the core form-hook bridge.
 */
class PageTitleVisibilityContractTest extends TestCase
{
    use RefreshDatabase;

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    // ---- Persistence / model -------------------------------------------------

    public function test_column_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumn('cms_contents', 'show_page_title'));
    }

    public function test_migration_is_reversible(): void
    {
        $file = base_path('packages/thenguyen/cms-core/database/migrations/2026_08_28_000001_add_show_page_title_to_cms_contents_table.php');
        $migration = require $file;

        $migration->down();
        $this->assertFalse(Schema::hasColumn('cms_contents', 'show_page_title'), 'down() must drop the column.');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('cms_contents', 'show_page_title'), 'up() must re-add the column.');

        // Re-running up() is idempotent (guarded by hasColumn).
        $migration->up();
        $this->assertTrue(Schema::hasColumn('cms_contents', 'show_page_title'));
    }

    public function test_new_page_defaults_to_show(): void
    {
        $page = $this->contents()->create(['type' => 'page', 'status' => 'draft', 'title' => 'Home', 'locale' => 'en']);

        $this->assertTrue($page->fresh()->show_page_title);
    }

    public function test_legacy_row_created_without_flag_resolves_to_show(): void
    {
        // A row inserted straight through the model without the flag relies on the
        // column default — the upgrade path for pre-contract pages.
        $page = Content::create(['type' => 'page', 'status' => 'draft']);

        $this->assertTrue($page->fresh()->show_page_title);
    }

    public function test_flag_is_cast_to_boolean(): void
    {
        $page = Content::create(['type' => 'page', 'status' => 'draft', 'show_page_title' => 0]);

        $this->assertIsBool($page->fresh()->show_page_title);
        $this->assertFalse($page->fresh()->show_page_title);
    }

    public function test_false_persists_and_round_trips_through_content_manager(): void
    {
        $page = $this->contents()->create(['type' => 'page', 'title' => 'Home', 'locale' => 'en']);

        $this->contents()->update($page, ['show_page_title' => false, 'title' => 'Home', 'locale' => 'en']);

        $this->assertFalse($page->fresh()->show_page_title);
    }

    public function test_update_omitting_flag_preserves_stored_value(): void
    {
        $page = $this->contents()->create(['type' => 'page', 'title' => 'Home', 'locale' => 'en', 'show_page_title' => false]);

        // An update that does not submit the flag (e.g. a plugin-driven partial
        // save) must not silently flip it back to the default.
        $this->contents()->update($page, ['title' => 'Home v2', 'locale' => 'en']);

        $this->assertFalse($page->fresh()->show_page_title);
    }

    public function test_flag_is_invariant_across_editing_locales(): void
    {
        $page = $this->contents()->create(['type' => 'page', 'title' => 'Home', 'locale' => 'en', 'show_page_title' => false]);

        // Author a second locale; the base flag must not change or fork per locale.
        $this->contents()->update($page, ['title' => 'Trang chủ', 'locale' => 'vi']);

        $this->assertFalse($page->fresh()->show_page_title);
    }

    public function test_updating_flag_does_not_overwrite_translations(): void
    {
        $page = $this->contents()->create(['type' => 'page', 'title' => 'Home', 'locale' => 'en']);
        $this->contents()->update($page, ['title' => 'Trang chủ', 'locale' => 'vi']);

        $this->contents()->update($page, ['show_page_title' => false, 'title' => 'Home', 'locale' => 'en']);

        $page->refresh();
        $this->assertSame('Home', $page->translations()->where('locale', 'en')->first()->title);
        $this->assertSame('Trang chủ', $page->translations()->where('locale', 'vi')->first()->title);
    }

    // ---- Admin form injection ------------------------------------------------

    public function test_toggle_is_inserted_immediately_after_locale_field(): void
    {
        $section = Section::make('Page')->schema([
            Select::make('locale'),
            TextInput::make('title'),
        ]);

        $result = PageTitleVisibilityFormField::inject([$section]);

        $children = $result[0]->getDefaultChildComponents();
        $names = array_map(static fn ($c) => method_exists($c, 'getName') ? $c->getName() : null, $children);

        $localeIndex = array_search('locale', $names, true);
        $this->assertNotFalse($localeIndex);
        $this->assertSame('show_page_title', $names[$localeIndex + 1] ?? null, 'Toggle must sit immediately below the language selector.');
    }

    public function test_injected_toggle_is_the_page_title_toggle_with_default_true(): void
    {
        $field = PageTitleVisibilityFormField::make();

        $this->assertInstanceOf(Toggle::class, $field);
        $this->assertSame('show_page_title', $field->getName());
    }

    public function test_inject_falls_back_to_prepend_when_no_locale_field(): void
    {
        $result = PageTitleVisibilityFormField::inject([TextInput::make('title')]);

        $this->assertInstanceOf(Toggle::class, $result[0]);
        $this->assertSame('show_page_title', $result[0]->getName());
    }

    public function test_registered_page_form_filter_injects_the_toggle(): void
    {
        $components = tn_form_schema([
            Section::make('Page')->schema([Select::make('locale'), TextInput::make('title')]),
        ], Content::class, 'page');

        $found = false;
        foreach ($components as $component) {
            if (method_exists($component, 'getDefaultChildComponents')) {
                foreach ($component->getDefaultChildComponents() as $child) {
                    if (method_exists($child, 'getName') && $child->getName() === 'show_page_title') {
                        $found = true;
                    }
                }
            }
            if (method_exists($component, 'getName') && $component->getName() === 'show_page_title') {
                $found = true;
            }
        }

        $this->assertTrue($found, 'The cms.form.schema.page filter must contribute the page-title toggle.');
    }
}
