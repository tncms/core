<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Filament\Admin\Resources\LanguageResource\Pages\ListLanguages;
use App\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Admin\Resources\MenuResource\RelationManagers\MenuItemsRelationManager;
use App\Filament\Admin\Resources\PageResource\Pages\ListPages;
use App\Filament\Admin\Resources\PostResource\Pages\ListPosts;
use App\Filament\Admin\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Admin\Resources\TaxonomyResource\Pages\ListTaxonomies;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldGroupResource\Pages\ListProfileFieldGroups;
use TheNguyen\CMS\Filament\Admin\Resources\ProfileFieldResource\Pages\ListProfileFields;
use TheNguyen\CMS\Models\Menu;

/**
 * CORE-RELEASE-BLOCKERS-R1-CLOSURE — Core-side mitigations for vendor-rendered accessibility findings
 * (brief §4, §5). No vendor file is edited: Core only chooses its own column options, its own link
 * class and one scoped stylesheet emitted through Filament's public HEAD_END render hook.
 *
 * A6 (WCAG 2.4.4 / 4.1.2): an icon-only boolean cell of a clickable row is rendered by Filament as an
 *     <a> with no accessible name. Core disables the row link on those cells (the row stays reachable
 *     through its named cells); the empty "Published at" of a draft gets a text placeholder.
 * A11 (WCAG 2.4.3 / 2.4.7): below Filament's lg breakpoint, the closed off-canvas sidebar is moved off
 *     screen but stays in the Tab order. Core hides it from the Tab order only while it is closed.
 * A5 (WCAG 1.4.3): Core's own text links used Amber-600 (3.2:1). They now use a Core link class with
 *     an AA shade and an underline.
 */
final class AdminAccessibilityClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    /** @return list<array{0: class-string, 1: string}> */
    public static function iconOnlyColumns(): array
    {
        return [
            [ListLanguages::class, 'is_default'],
            [ListLanguages::class, 'is_active'],
            [ListRoles::class, 'is_system'],
            [ListTaxonomies::class, 'hierarchical'],
            [ListTaxonomies::class, 'is_core'],
            [ListProfileFieldGroups::class, 'is_active'],
            [ListProfileFieldGroups::class, 'is_locked'],
            [ListProfileFields::class, 'is_encrypted'],
            [ListProfileFields::class, 'is_active'],
        ];
    }

    #[DataProvider('iconOnlyColumns')]
    public function test_a6_icon_only_boolean_cells_are_not_unnamed_row_links(string $page, string $column): void
    {
        $table = Livewire::test($page)->instance()->getTable();

        $this->assertTrue($table->getColumn($column)->isClickDisabled(), $page.'::'.$column);
    }

    public function test_a6_menu_item_active_cell_is_not_an_unnamed_row_link(): void
    {
        $menu = Menu::query()->create(['slug' => 'header', 'location' => 'header', 'status' => 'published', 'is_system' => false, 'sort_order' => 0]);

        $table = Livewire::test(MenuItemsRelationManager::class, ['ownerRecord' => $menu, 'pageClass' => EditMenu::class])
            ->instance()->getTable();

        $this->assertTrue($table->getColumn('is_active')->isClickDisabled());
    }

    public function test_a6_rendered_language_list_has_no_link_inside_boolean_cells(): void
    {
        $html = Livewire::test(ListLanguages::class)->html();

        $this->assertSame(0, preg_match('/fi-ta-cell-is-(default|active)[^>]*>\s*<a\b/', $html), 'no <a> wraps an icon-only cell');
        $this->assertGreaterThan(0, preg_match('/fi-ta-cell-is-default/', $html), 'fixture: the column is rendered');
    }

    /** @return list<array{0: class-string}> */
    public static function contentLists(): array
    {
        return [[ListPosts::class], [ListPages::class]];
    }

    #[DataProvider('contentLists')]
    public function test_a6_empty_published_at_has_a_text_placeholder(string $page): void
    {
        $column = Livewire::test($page)->instance()->getTable()->getColumn('published_at');

        $this->assertNotNull($column->getPlaceholder());
        $this->assertNotSame('', trim((string) $column->getPlaceholder()));
    }

    /** Filament draws placeholders in gray-400 (2.62:1); the Core cell class lifts the text to AA (WCAG 1.4.3). */
    #[DataProvider('contentLists')]
    public function test_a6_placeholder_cell_carries_the_aa_placeholder_class(string $page): void
    {
        $column = Livewire::test($page)->instance()->getTable()->getColumn('published_at');

        $this->assertStringContainsString('tn-admin-placeholder', (string) $column->getExtraCellAttributeBag()->get('class'));
    }

    public function test_a6_aa_placeholder_rule_is_in_the_core_admin_stylesheet(): void
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();
        preg_match('/<style[^>]*data-tn-admin-a11y[^>]*>(.*?)<\/style>/s', $html, $m);

        $this->assertStringContainsString('.tn-admin-placeholder .fi-ta-placeholder', $m[1] ?? '');
    }

    public function test_a11_and_a5_core_admin_stylesheet_is_emitted_in_the_admin_head(): void
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<style[^>]*data-tn-admin-a11y[^>]*>/', $html);
        preg_match('/<style[^>]*data-tn-admin-a11y[^>]*>(.*?)<\/style>/s', $html, $m);
        $css = $m[1] ?? '';
        // A11: scoped to the closed main sidebar, below Filament's lg breakpoint (64rem) only.
        $this->assertStringContainsString('.fi-sidebar.fi-main-sidebar:not(.fi-sidebar-open)', $css);
        $this->assertStringContainsString('visibility: hidden', $css);
        $this->assertStringContainsString('64rem', $css);
        // A5: Core link class.
        $this->assertStringContainsString('.tn-admin-link', $css);
        $this->assertStringContainsString('text-decoration-line: underline', $css);
    }

    public function test_a5_core_text_links_use_the_aa_link_class(): void
    {
        $html = (string) $this->get('/admin/xml-sitemap')->assertOk()->getContent();

        $this->assertSame(2, preg_match_all('/<a\b(?=[^>]*class="[^"]*\btn-admin-link\b)(?=[^>]*target="_blank")[^>]*>/', $html));
        $this->assertStringNotContainsString('text-primary-600', $html);

        foreach (['xml-sitemap', 'theme-options-page'] as $view) {
            $source = (string) file_get_contents(resource_path('views/filament/admin/pages/'.$view.'.blade.php'));
            $this->assertStringNotContainsString('text-primary-600', $source, $view);
        }
    }
}
