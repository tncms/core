<?php

declare(strict_types=1);

namespace Tests\Feature\AdminA11yM1;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;

/**
 * CORE-40-H-Z1-M3 — Filament draws a boolean IconColumn as an aria-hidden SVG with no text, so the
 * yes / no state of a populated row reaches no assistive technology (WCAG 1.1.1, 1.3.1). Every Core
 * boolean icon cell must expose its state as a localized text alternative.
 */
final class BooleanIconStateTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $this->seed(CmsRolePermissionSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
    }

    private function signIn(string $locale): void
    {
        $admin = User::factory()->create(['admin_locale' => $locale]);
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);
    }

    private function html(string $url): string
    {
        return $this->get($url)->assertOk()->getContent();
    }

    /** @return list<string> aria-label of each boolean cell's state element (one entry per rendered row) */
    private function stateNames(string $html, string $column): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $x = new DOMXPath($dom);
        $cell = 'fi-ta-cell-'.str_replace('_', '-', $column);
        $names = [];
        foreach ($x->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' {$cell} ')]") as $td) {
            $named = $x->query('.//*[@role="img"][@aria-label]', $td);
            $names[] = $named->length === 1 ? $named->item(0)->getAttribute('aria-label') : '';
        }

        return $names;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function columns(): array
    {
        return [
            ['/admin/languages', 'is_default'],
            ['/admin/languages', 'is_active'],
            ['/admin/roles', 'is_system'],
            ['/admin/taxonomies', 'hierarchical'],
            ['/admin/taxonomies', 'is_core'],
            ['/admin/profile-field-groups', 'is_active'],
            ['/admin/profile-field-groups', 'is_locked'],
            ['/admin/profile-fields', 'is_encrypted'],
            ['/admin/profile-fields', 'is_active'],
        ];
    }

    #[DataProvider('columns')]
    public function test_every_boolean_cell_exposes_its_state_as_text(string $url, string $column): void
    {
        $this->signIn('en');

        $names = $this->stateNames($this->html($url), $column);

        $this->assertNotEmpty($names, 'fixture: the column has populated rows');
        foreach ($names as $name) {
            $this->assertContains($name, ['Yes', 'No'], "{$url} {$column} state must be named");
        }
    }

    public function test_both_states_are_distinguished(): void
    {
        $this->signIn('en');

        $names = array_values(array_unique($this->stateNames($this->html('/admin/languages'), 'is_default')));
        sort($names);

        $this->assertSame(['No', 'Yes'], $names, 'one default and one non-default language');
    }

    public function test_menu_item_active_state_is_named(): void
    {
        $this->signIn('en');
        $menu = Menu::query()->create(['slug' => 'header', 'location' => 'header', 'status' => 'published', 'is_system' => false, 'sort_order' => 0]);
        MenuItem::query()->create(['menu_id' => $menu->id, 'type' => 'custom', 'url' => '/a', 'sort_order' => 1, 'is_active' => true]);
        MenuItem::query()->create(['menu_id' => $menu->id, 'type' => 'custom', 'url' => '/b', 'sort_order' => 2, 'is_active' => false]);

        $names = $this->stateNames($this->html('/admin/menus/'.$menu->id.'/edit'), 'is_active');
        sort($names);

        $this->assertSame(['No', 'Yes'], $names);
    }

    public function test_state_names_are_localized_in_vietnamese(): void
    {
        $this->signIn('vi');

        $names = array_values(array_unique($this->stateNames($this->html('/admin/languages'), 'is_default')));
        sort($names);

        $this->assertSame(['Có', 'Không'], $names);
    }
}
