<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Admin\Resources\MenuResource\RelationManagers\MenuItemsRelationManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;

/**
 * CORE-MENU-URL-1 — certifies the shared public menu-URL contract through the
 * real pipeline. The hard invariant: no stored/custom menu URL that the canonical
 * policy classifies as unsafe may be emitted as an executable navigation href by
 * {@see \frontend_menu()} / {@see \TheNguyen\CMS\Services\MenuManager::tree()} or
 * by the Default Theme — even for legacy/imported/direct-DB/obfuscated rows — and
 * the stored row is never rewritten (rejection is a presentation decision).
 */
final class MenuUrlSafetyContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // vi default (unprefixed), en secondary — so resolvedUrl()/current locale
        // resolve exactly like production.
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    private function makeMenu(string $location = 'header'): Menu
    {
        return Menu::query()->create([
            'slug' => $location,
            'location' => $location,
            'status' => 'published',
            'is_system' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * Create a custom menu item with a per-locale title + legacy/shared URL column.
     */
    private function customItem(Menu $menu, string $url, string $title = 'Link', int $sort = 0): MenuItem
    {
        $item = $menu->items()->create([
            'type' => 'custom',
            'url' => $url,
            'target' => '_self',
            'sort_order' => $sort,
            'is_active' => true,
        ]);

        $item->translations()->create(['locale' => 'vi', 'title' => $title]);
        $item->translations()->create(['locale' => 'en', 'title' => $title]);

        return $item;
    }

    // --- Public hydration contract (frontend_menu / MenuManager::tree) ---------

    public function test_safe_custom_url_is_linkable_and_preserved(): void
    {
        $menu = $this->makeMenu();
        $this->customItem($menu, '/about');

        $tree = menu()->tree($menu, 'vi');

        $this->assertCount(1, $tree);
        $this->assertSame('/about', $tree[0]['url']);
        $this->assertTrue($tree[0]['linkable']);
    }

    public function test_unsafe_custom_url_is_neutralized_but_stored_value_preserved(): void
    {
        $menu = $this->makeMenu();
        $item = $this->customItem($menu, 'javascript:alert(document.cookie)');

        $tree = menu()->tree($menu, 'vi');

        // Public contract: non-executable, non-linkable.
        $this->assertSame('', $tree[0]['url']);
        $this->assertFalse($tree[0]['linkable']);

        // Stored row is NOT rewritten — rejection is presentation-only.
        $this->assertSame(
            'javascript:alert(document.cookie)',
            DB::table('cms_menu_items')->where('id', $item->id)->value('url'),
        );
    }

    public function test_legacy_unsafe_row_inserted_directly_is_neutralized(): void
    {
        // A row that bypasses Eloquent and the Admin form entirely (legacy data,
        // a raw import, or a direct DB edit) must still be neutralized at render.
        $menu = $this->makeMenu();
        $id = DB::table('cms_menu_items')->insertGetId([
            'menu_id' => $menu->id,
            'parent_id' => null,
            'type' => 'custom',
            'url' => 'javascript:alert(1)',
            'target' => '_self',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cms_menu_item_translations')->insert([
            'menu_item_id' => $id,
            'locale' => 'vi',
            'title' => 'Legacy',
            'url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tree = menu()->tree($menu, 'vi');

        $this->assertSame('', $tree[0]['url']);
        $this->assertFalse($tree[0]['linkable']);
        $this->assertSame('javascript:alert(1)', DB::table('cms_menu_items')->where('id', $id)->value('url'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function obfuscatedSchemes(): array
    {
        return [
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'leading whitespace' => ['   javascript:alert(1)'],
            'embedded newline' => ["java\nscript:alert(1)"],
            'embedded tab' => ["java\tscript:alert(1)"],
            'data html' => ['data:text/html,<script>alert(1)</script>'],
            'vbscript' => ['vbscript:msgbox(1)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('obfuscatedSchemes')]
    public function test_obfuscated_schemes_are_neutralized_through_the_pipeline(string $url): void
    {
        $menu = $this->makeMenu();
        $id = DB::table('cms_menu_items')->insertGetId([
            'menu_id' => $menu->id,
            'type' => 'custom',
            'url' => $url,
            'target' => '_self',
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('cms_menu_item_translations')->insert([
            'menu_item_id' => $id, 'locale' => 'vi', 'title' => 'X', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $tree = menu()->tree($menu, 'vi');

        $this->assertSame('', $tree[0]['url']);
        $this->assertFalse($tree[0]['linkable']);
    }

    public function test_per_locale_translation_urls_are_classified_independently(): void
    {
        $menu = $this->makeMenu();
        $item = $menu->items()->create([
            'type' => 'custom', 'url' => null, 'target' => '_self', 'sort_order' => 0, 'is_active' => true,
        ]);
        $item->translations()->create(['locale' => 'vi', 'title' => 'An toàn', 'url' => '/vi-trang']);
        $item->translations()->create(['locale' => 'en', 'title' => 'Bad', 'url' => 'javascript:alert(1)']);

        $viTree = menu()->tree($menu, 'vi');
        $enTree = menu()->tree($menu, 'en');

        $this->assertSame('/vi-trang', $viTree[0]['url']);
        $this->assertTrue($viTree[0]['linkable']);

        $this->assertSame('', $enTree[0]['url']);
        $this->assertFalse($enTree[0]['linkable']);
    }

    public function test_hash_placeholder_parent_stays_linkable_with_children(): void
    {
        $menu = $this->makeMenu();
        $parent = $this->customItem($menu, '#', 'Parent', 0);
        $child = $menu->items()->create([
            'type' => 'custom', 'parent_id' => $parent->id, 'url' => '/child', 'target' => '_self', 'sort_order' => 0, 'is_active' => true,
        ]);
        $child->translations()->create(['locale' => 'vi', 'title' => 'Child']);

        $tree = menu()->tree($menu, 'vi');

        // '#' is a non-navigating dropdown parent, not an unsafe scheme — stays linkable.
        $this->assertSame('#', $tree[0]['url']);
        $this->assertTrue($tree[0]['linkable']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('/child', $tree[0]['children'][0]['url']);
        $this->assertTrue($tree[0]['children'][0]['linkable']);
    }

    public function test_frontend_menu_helper_exposes_the_linkable_contract(): void
    {
        $menu = $this->makeMenu('header');
        $this->customItem($menu, 'javascript:alert(1)', 'Danger', 0);
        $this->customItem($menu, 'https://example.com', 'Safe', 1);

        $nodes = frontend_menu('header');

        $this->assertFalse($nodes[0]['linkable']);
        $this->assertSame('', $nodes[0]['url']);
        $this->assertTrue($nodes[1]['linkable']);
        $this->assertSame('https://example.com', $nodes[1]['url']);
    }

    public function test_entity_linked_item_is_preserved_and_linkable(): void
    {
        // Entity (page/post/term) URLs are route-derived and always safe; the
        // policy must never drop them. Even the '#' fallback is a safe, linkable
        // target, so an entity item is linkable regardless of resolution.
        $menu = $this->makeMenu();
        $item = $menu->items()->create([
            'type' => 'page',
            'reference_type' => 'content',
            'reference_id' => 999999, // unresolved → resolvedUrl() falls back to '#'
            'url' => null,
            'target' => '_self',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $item->translations()->create(['locale' => 'vi', 'title' => 'Page']);

        $tree = menu()->tree($menu, 'vi');

        $this->assertTrue($tree[0]['linkable']);
        $this->assertNotSame('', $tree[0]['url']);
    }

    // --- Default Theme render (the first-party theme half of the defense) ------

    public function test_default_theme_renders_span_not_anchor_for_non_linkable_node(): void
    {
        View::replaceNamespace('theme', [base_path('themes/default/views')]);

        $node = $this->fakeNode(linkable: false, url: '', title: 'Rejected');

        $html = View::make('theme::partials.menu-item', [
            'node' => $node,
            'currentPath' => '/',
            'isNodeActive' => fn (): bool => false,
        ])->render();

        $this->assertStringContainsString('menu__link--nolink', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringContainsString('Rejected', $html);
    }

    public function test_default_theme_renders_anchor_for_linkable_node(): void
    {
        View::replaceNamespace('theme', [base_path('themes/default/views')]);

        $node = $this->fakeNode(linkable: true, url: '/about', title: 'About');

        $html = View::make('theme::partials.menu-item', [
            'node' => $node,
            'currentPath' => '/',
            'isNodeActive' => fn (): bool => false,
        ])->render();

        $this->assertStringContainsString('href="/about"', $html);
        $this->assertStringContainsString('About', $html);
    }

    /**
     * Build a menu tree node matching the MenuManager::buildTree() public shape.
     *
     * @return array<string, mixed>
     */
    private function fakeNode(bool $linkable, string $url, string $title): array
    {
        $item = new MenuItem(['type' => 'custom', 'target' => '_self']);
        $item->id = 1;

        return [
            'item' => $item,
            'title' => $title,
            'url' => $url,
            'linkable' => $linkable,
            'children' => [],
            'meta' => ['display' => 'normal'],
        ];
    }

    // --- Performance & backward compatibility ----------------------------------

    public function test_url_policy_adds_no_database_queries(): void
    {
        // The policy is pure PHP: hydrating a menu with unsafe URLs must issue the
        // exact same number of queries as hydrating one with safe URLs.
        $safeMenu = $this->makeMenu('safe-nav');
        $this->customItem($safeMenu, '/one', 'One', 0);
        $this->customItem($safeMenu, '/two', 'Two', 1);

        $unsafeMenu = $this->makeMenu('unsafe-nav');
        $this->customItem($unsafeMenu, 'javascript:alert(1)', 'One', 0);
        $this->customItem($unsafeMenu, 'data:text/html,x', 'Two', 1);

        $safeCount = $this->countQueries(fn () => menu()->tree($safeMenu->fresh(), 'vi'));
        $unsafeCount = $this->countQueries(fn () => menu()->tree($unsafeMenu->fresh(), 'vi'));

        $this->assertSame($safeCount, $unsafeCount);
    }

    private function countQueries(callable $fn): int
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };
        DB::listen($listener);
        $fn();

        return $count;
    }

    public function test_legacy_theme_ignoring_linkable_still_receives_safe_href(): void
    {
        // A theme written before the `linkable` key existed renders
        // <a href="{{ $node['url'] }}">. For a rejected URL the contract drops the
        // url to '' — so even that old theme emits an inert href="" rather than an
        // executable scheme. Prove the url is exactly '' (never the payload).
        $menu = $this->makeMenu();
        $this->customItem($menu, 'javascript:alert(1)');

        $node = menu()->tree($menu, 'vi')[0];

        $this->assertSame('', $node['url']);
        $this->assertStringNotContainsString('javascript', $node['url']);
    }

    // --- Admin write-time validation (§9) --------------------------------------

    private function superAdmin(): User
    {
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret'),
        ]);
        $this->actingAs($user);

        return $user;
    }

    public function test_admin_rejects_unsafe_custom_url(): void
    {
        $this->superAdmin();
        $menu = $this->makeMenu();

        Livewire::test(MenuItemsRelationManager::class, [
            'ownerRecord' => $menu,
            'pageClass' => EditMenu::class,
        ])
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'Danger',
                'type' => 'custom',
                'url' => 'javascript:alert(1)',
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['url']);

        $this->assertDatabaseMissing('cms_menu_items', ['url' => 'javascript:alert(1)']);
    }

    public function test_admin_accepts_safe_custom_url(): void
    {
        $this->superAdmin();
        $menu = $this->makeMenu();

        Livewire::test(MenuItemsRelationManager::class, [
            'ownerRecord' => $menu,
            'pageClass' => EditMenu::class,
        ])
            ->mountTableAction('create')
            ->setTableActionData([
                'title' => 'Safe',
                'type' => 'custom',
                'url' => '/contact',
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        // Custom URLs are persisted per-locale in the translation table; the item
        // was created and its safe URL accepted (and renders linkable via the tree).
        $this->assertDatabaseHas('cms_menu_item_translations', ['url' => '/contact']);
        $this->assertSame('/contact', menu()->tree($menu->fresh(), 'vi')[0]['url']);
    }
}
