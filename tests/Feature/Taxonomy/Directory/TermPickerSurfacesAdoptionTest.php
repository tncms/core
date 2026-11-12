<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Directory;

use App\Filament\Admin\Pages\HomepageLayoutPage;
use App\Filament\Admin\Pages\SettingsPage;
use App\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Admin\Resources\MenuResource\RelationManagers\MenuItemsRelationManager;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Filament\Forms\TermSelect;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Taxonomy\Directory\TaxonomyDirectory;

/**
 * CORE-P16-1: the remaining Core admin term pickers adopted onto the directory — menu items
 * (linked category/tag, mega categories/tags), Settings → Writing default category, and the
 * Homepage layout "terms" fields. Each accepts a term beyond the first page and rejects ids of
 * the wrong taxonomy or unknown ids on the server.
 */
final class TermPickerSurfacesAdoptionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<Term> */
    private array $categories = [];

    private Term $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();

        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        for ($i = 1; $i <= TaxonomyDirectory::DEFAULT_LIMIT + 5; $i++) {
            $this->categories[] = app('cms.taxonomy')->createTerm('category', [
                'locale' => 'vi', 'name' => sprintf('Mục %02d', $i), 'sort_order' => $i,
            ]);
        }

        $this->tag = app('cms.taxonomy')->createTerm('tag', ['locale' => 'vi', 'name' => 'Thẻ một']);
    }

    private function last(): Term
    {
        return $this->categories[array_key_last($this->categories)];
    }

    private function menu(): Menu
    {
        return Menu::query()->create(['slug' => 'header', 'location' => 'header', 'status' => 'published', 'is_system' => false, 'sort_order' => 0]);
    }

    private function createMenuItem(Menu $menu, array $data)
    {
        return Livewire::test(MenuItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => EditMenu::class])
            ->mountTableAction('create')
            ->setTableActionData($data)
            ->callMountedTableAction();
    }

    public function test_menu_item_links_a_category_beyond_the_first_page(): void
    {
        $menu = $this->menu();

        $this->createMenuItem($menu, ['title' => 'Mục cuối', 'type' => 'category', 'reference_id' => (string) $this->last()->id])
            ->assertHasNoTableActionErrors();

        $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
        $this->assertSame($this->last()->id, (int) $item->reference_id);
    }

    public function test_menu_item_rejects_a_tag_or_unknown_id_for_a_category_link(): void
    {
        $menu = $this->menu();

        foreach ([$this->tag->id, 999999] as $bad) {
            $this->createMenuItem($menu, ['title' => 'Sai', 'type' => 'category', 'reference_id' => (string) $bad])
                ->assertHasTableActionErrors(['reference_id']);
        }

        $this->assertSame(0, MenuItem::query()->count());
    }

    public function test_menu_reference_search_is_bounded_for_terms_and_unchanged_for_pages(): void
    {
        $search = new ReflectionMethod(MenuItemsRelationManager::class, 'referenceSearch');

        $this->assertSame([$this->last()->id => 'Mục 25'], $search->invoke(null, 'category', 'muc 25'));
        $this->assertCount(TaxonomyDirectory::DEFAULT_LIMIT, $search->invoke(null, 'category', 'Mục'));
        $this->assertSame([$this->tag->id => 'Thẻ một'], $search->invoke(null, 'tag', 'thẻ'));
        $this->assertSame([], $search->invoke(null, 'page', 'nothing'));
    }

    public function test_mega_menu_term_lists_accept_terms_beyond_the_first_page_and_reject_wrong_taxonomy(): void
    {
        $menu = $this->menu();
        $base = ['title' => 'Mega', 'type' => 'custom', 'url' => '/mega', 'display' => 'mega'];

        $this->createMenuItem($menu, $base + ['mega_source' => 'categories', 'mega_category_ids' => [(string) $this->last()->id]])
            ->assertHasNoTableActionErrors();
        $this->assertSame([$this->last()->id], MenuItem::query()->firstOrFail()->resolvedMeta('vi')['mega_category_ids']);

        $this->createMenuItem($menu, $base + ['mega_source' => 'categories', 'mega_category_ids' => [(string) $this->tag->id]])
            ->assertHasTableActionErrors(['mega_category_ids.0']);

        $this->createMenuItem($menu, $base + ['mega_source' => 'tags', 'mega_tag_ids' => [(string) $this->tag->id]])
            ->assertHasNoTableActionErrors();
    }

    public function test_settings_default_category_accepts_any_category_and_rejects_a_tag(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', (string) $this->last()->id)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame($this->last()->id, (int) settings('writing.default_category_id'));

        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Test Site')
            ->set('data.writing_default_category_id', (string) $this->tag->id)
            ->call('save')
            ->assertHasErrors(['data.writing_default_category_id']);
    }

    public function test_settings_default_category_reopens_with_its_label(): void
    {
        app('cms.settings')->set('writing.default_category_id', $this->last()->id, 'integer');

        $lw = Livewire::test(SettingsPage::class)->assertSet('data.writing_default_category_id', $this->last()->id);
        $field = $lw->instance()->getSchemaComponent('form.writing_default_category_id')
            ?? collect($lw->instance()->getSchema('form')->getFlatComponents())
                ->first(fn ($c) => $c instanceof TermSelect && str_ends_with((string) $c->getStatePath(), 'writing_default_category_id'));

        $this->assertInstanceOf(TermSelect::class, $field);
        $this->assertSame('Mục 25', $field->getOptionLabel(withDefault: false));
    }

    public function test_homepage_terms_fields_use_the_directory_only_for_supported_taxonomies(): void
    {
        $page = new HomepageLayoutPage;
        $field = new ReflectionMethod($page, 'termsField');

        $category = $field->invoke($page, 'data.x', 'category');
        $this->assertInstanceOf(TermSelect::class, $category);
        $this->assertSame('category', $category->getTermTaxonomy());
        $this->assertFalse($category->requiresTermTranslation(), 'admin-locale labels with fallback, as before');

        $other = $field->invoke($page, 'data.y', 'product_category');
        $this->assertInstanceOf(Select::class, $other);
        $this->assertNotInstanceOf(TermSelect::class, $other);
    }
}
