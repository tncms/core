<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;

/**
 * CORE-40 L10N-MENU-1. MenuItem::displayTitle() documents its contract as
 * "requested locale, then the first available translation, then a deterministic
 * placeholder". MenuManager::tree() eager-loaded only the requested locale's
 * translation rows, so on the public menu the fallback step had nothing to fall
 * back to: an item translated only in vi rendered "Item #n" on /en.
 *
 * Contract: the public tree resolves the title through the documented fallback;
 * the placeholder appears only when the item has no translation at all; stored
 * rows are never written; titles stay escaped; no per-item query is added.
 */
final class MenuTranslationFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function menu(): Menu
    {
        return Menu::query()->create(['slug' => 'header', 'location' => 'header', 'status' => 'active', 'is_system' => false, 'sort_order' => 0]);
    }

    /** @param  array<string, string>  $titles  locale => title */
    private function item(Menu $menu, array $titles, int $sort = 0): MenuItem
    {
        $item = $menu->items()->create(['type' => 'custom', 'url' => '/about-'.$sort, 'target' => '_self', 'sort_order' => $sort, 'is_active' => true]);
        foreach ($titles as $locale => $title) {
            $item->translations()->create(['locale' => $locale, 'title' => $title]);
        }

        return $item;
    }

    private function title(Menu $menu, string $locale, int $index = 0): string
    {
        return menu()->tree($menu, $locale)[$index]['title'];
    }

    public function test_requested_locale_title_is_used_in_both_languages(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['vi' => 'Giới thiệu', 'en' => 'About']);

        $this->assertSame('About', $this->title($menu, 'en'));
        $this->assertSame('Giới thiệu', $this->title($menu, 'vi'));
    }

    public function test_missing_english_title_falls_back_to_the_existing_translation(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, ['vi' => 'Trang chủ']);

        $this->assertSame('Trang chủ', $this->title($menu, 'en'));
        $this->assertNotSame('Item #'.$item->id, $this->title($menu, 'en'));
    }

    public function test_missing_vietnamese_title_falls_back_to_the_existing_translation(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['en' => 'Home']);

        $this->assertSame('Home', $this->title($menu, 'vi'));
    }

    public function test_placeholder_only_when_no_translation_exists(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, []);

        $this->assertSame('Item #'.$item->id, $this->title($menu, 'en'));
        $this->assertSame('Item #'.$item->id, $this->title($menu, 'vi'));
    }

    public function test_tree_title_matches_the_model_contract(): void
    {
        $menu = $this->menu();
        $item = $this->item($menu, ['vi' => 'Liên hệ']);

        $this->assertSame($item->fresh()->displayTitle('en'), $this->title($menu, 'en'));
    }

    public function test_frontend_menu_helper_uses_the_fallback(): void
    {
        $this->item($this->menu(), ['vi' => 'Bài viết đầu tiên']);

        $this->assertSame('Bài viết đầu tiên', frontend_menu('header', 'en')[0]['title']);
    }

    public function test_custom_url_stays_exact_locale_while_the_title_falls_back(): void
    {
        $menu = $this->menu();
        $item = $menu->items()->create(['type' => 'custom', 'url' => null, 'target' => '_self', 'sort_order' => 0, 'is_active' => true]);
        $item->translations()->create(['locale' => 'vi', 'title' => 'Liên hệ', 'url' => '/lien-he']);

        $node = menu()->tree($menu, 'en')[0];
        $this->assertSame('Liên hệ', $node['title']);
        $this->assertSame('#', $node['url'], 'no cross-locale URL fallback (MenuItem::customUrl contract)');
    }

    public function test_special_characters_round_trip_and_render_escaped(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['vi' => 'Cà phê & Đồ ăn "ngon" <3']);

        $node = menu()->tree($menu, 'en')[0];
        $this->assertSame('Cà phê & Đồ ăn "ngon" <3', $node['title']);

        $html = $this->renderNode($node);
        $this->assertStringContainsString('Cà phê &amp; Đồ ăn &quot;ngon&quot; &lt;3', $html);
    }

    public function test_markup_in_a_fallback_title_stays_escaped(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['vi' => '"><b data-tgd="1">x</b><script>tgdMenu()</script>']);

        $html = $this->renderNode(menu()->tree($menu, 'en')[0]);

        $this->assertStringNotContainsString('<script>tgdMenu()', $html);
        $this->assertStringNotContainsString('<b data-tgd', $html);
        $this->assertStringContainsString('&lt;script&gt;tgdMenu()&lt;/script&gt;', $html);
    }

    public function test_public_english_page_gives_the_menu_link_a_meaningful_name(): void
    {
        $item = $this->item($this->menu(), ['vi' => 'Trang chủ']);

        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="/about-0"[^>]*class="menu__link"[^>]*>\s*<span class="menu__label">Trang chủ</span>#u', $html);
        $this->assertStringNotContainsString('Item #'.$item->id, $html);
    }

    public function test_stored_translations_are_not_written(): void
    {
        $menu = $this->menu();
        $this->item($menu, ['vi' => 'Trang chủ']);
        $before = DB::table('cms_menu_item_translations')->orderBy('id')->get()->toJson();

        menu()->tree($menu, 'en');
        $this->get('/en')->assertOk();

        $this->assertSame($before, DB::table('cms_menu_item_translations')->orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('cms_menu_item_translations')->where('locale', 'en')->count());
    }

    public function test_the_fallback_adds_no_per_item_query(): void
    {
        $one = $this->menu();
        $this->item($one, ['vi' => 'Một']);
        $count = function (Menu $menu): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            menu()->tree($menu->fresh(), 'en');
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $single = $count($one);

        $many = Menu::query()->create(['slug' => 'many', 'location' => 'many', 'status' => 'active', 'is_system' => false, 'sort_order' => 1]);
        for ($i = 0; $i < 6; $i++) {
            $this->item($many, ['vi' => "Mục $i"], $i);
        }

        $this->assertSame($single, $count($many), 'translations are eager-loaded once for the whole tree');
    }

    /** @param  array<string, mixed>  $node */
    private function renderNode(array $node): string
    {
        View::replaceNamespace('theme', [base_path('themes/default/views')]);

        return View::make('theme::partials.menu-item', ['node' => $node, 'currentPath' => '/', 'isNodeActive' => fn (): bool => false])->render();
    }
}
