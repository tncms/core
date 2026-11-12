<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Support\Account\AccountNavItem;

/**
 * CORE-P12-2/3 — the legacy `cms.account.navigation_items` filter.
 *
 * The filter stays supported for adding a plugin's own items, but its output is
 * untrusted: it can no longer remove or replace Core items, and malformed or
 * unsafe items are dropped instead of breaking the account page.
 */
final class AccountNavigationLegacyHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const CORE_KEYS = ['dashboard', 'profile', 'security', 'sessions', 'preferences'];

    /** @return list<string> */
    private function keys(): array
    {
        return array_map(static fn (AccountNavItem $i): string => $i->key, app('cms.account')->navigationItems(User::factory()->create()));
    }

    public function test_a_filter_cannot_remove_core_items(): void
    {
        add_filter('cms.account.navigation_items', static fn (): array => []);

        $this->assertSame(self::CORE_KEYS, $this->keys());
    }

    public function test_a_filter_cannot_replace_or_relabel_a_core_item(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            foreach ($items as $i => $item) {
                if ($item->key === 'profile') {
                    $items[$i] = new AccountNavItem('profile', 'Hijacked', 'https://evil.test/phish', null, 1);
                }
            }

            return $items;
        });

        $profile = collect(app('cms.account')->navigationItems(User::factory()->create()))->firstWhere('key', 'profile');

        $this->assertSame('Profile', $profile->label);
        $this->assertSame(route('cms.account.profile'), $profile->url);
        $this->assertSame(20, $profile->priority);
    }

    public function test_a_plugin_item_cannot_claim_a_core_key(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('security', 'Fake security', '/account/fake', null, 5);

            return $items;
        });

        $items = app('cms.account')->navigationItems(User::factory()->create());

        $this->assertSame(1, collect($items)->where('key', 'security')->count());
        $this->assertSame('Security', collect($items)->firstWhere('key', 'security')->label);
    }

    public function test_a_filter_returning_null_does_not_break_the_account_page(): void
    {
        add_filter('cms.account.navigation_items', static fn () => null);

        $this->actingAs(User::factory()->create())->get('/account')
            ->assertOk()
            ->assertSee(route('cms.account.profile'), false);
    }

    public function test_a_filter_returning_a_string_does_not_break_the_account_page(): void
    {
        add_filter('cms.account.navigation_items', static fn () => 'not a list');

        $this->actingAs(User::factory()->create())->get('/account')
            ->assertOk()
            ->assertSee(route('cms.account.security'), false);
    }

    public function test_structurally_malformed_items_are_dropped(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            return array_merge($items, [
                null,
                'just a string',
                42,
                ['key' => 'no-label', 'url' => '/account/x'],
                ['label' => 'No key', 'url' => '/account/y'],
                ['key' => 'nested-label', 'label' => ['en' => 'x'], 'url' => '/account/z'],
                ['key' => 'nested-url', 'label' => 'Nested', 'url' => ['/account/z']],
                ['key' => 'no-url', 'label' => 'No URL'],
                ['key' => 'ok', 'label' => 'Valid item', 'url' => '/account/ok', 'priority' => 60],
            ]);
        });

        $this->assertSame([...self::CORE_KEYS, 'ok'], $this->keys());

        $this->actingAs(User::factory()->create())->get('/account')->assertOk()->assertSee('Valid item');
    }

    public function test_unsafe_urls_are_dropped(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            return array_merge($items, [
                new AccountNavItem('js', 'Js link', 'javascript:alert(document.cookie)', null, 60),
                new AccountNavItem('js-upper', 'Js upper', ' JaVaScRiPt:alert(1)', null, 60),
                new AccountNavItem('data', 'Data link', 'data:text/html;base64,PHNjcmlwdD4=', null, 60),
                new AccountNavItem('vbs', 'Vbs link', 'vbscript:msgbox(1)', null, 60),
                new AccountNavItem('proto-relative', 'Proto relative', '//evil.test/account', null, 60),
                new AccountNavItem('backslash', 'Backslash', '/\\evil.test/account', null, 60),
                new AccountNavItem('control', 'Control', "/account/a\nb", null, 60),
                new AccountNavItem('relative-path', 'Relative path', 'account/orders', null, 60),
                new AccountNavItem('hash', 'Hash only', '#', null, 60),
                new AccountNavItem('off-site', 'Off-site phishing', 'https://evil.test/account/security', null, 60),
                new AccountNavItem('userinfo', 'Userinfo', 'http://localhost@evil.test/account', null, 60),
                new AccountNavItem('rel', 'Root relative', '/account/orders', null, 61),
                new AccountNavItem('abs', 'Absolute own site', 'http://localhost/account/orders', null, 62),
            ]);
        });

        $this->assertSame([...self::CORE_KEYS, 'rel', 'abs'], $this->keys());

        $html = $this->actingAs(User::factory()->create())->get('/account')->assertOk()->getContent();
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringNotContainsString('evil.test', $html);
    }

    public function test_a_negative_priority_cannot_place_an_item_above_every_core_item(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('first', 'First', '/account/first', null, -500);

            return $items;
        });

        $items = app('cms.account')->navigationItems(User::factory()->create());

        $this->assertSame(0, collect($items)->firstWhere('key', 'first')->priority);
        $this->assertSame(['first', 'dashboard'], array_slice(array_map(static fn ($i) => $i->key, $items), 0, 2));
    }

    public function test_duplicate_plugin_keys_keep_the_first(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('orders', 'Orders', '/account/orders', null, 60);
            $items[] = new AccountNavItem('orders', 'Orders again', '/account/orders-2', null, 61);

            return $items;
        });

        $items = collect(app('cms.account')->navigationItems(User::factory()->create()))->where('key', 'orders');

        $this->assertCount(1, $items);
        $this->assertSame('/account/orders', $items->first()->url);
    }

    public function test_html_in_labels_and_badges_is_escaped(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = new AccountNavItem('xss', '<img src=x onerror=alert(1)>', '/account/xss', null, 60, badge: '<b>9</b>');

            return $items;
        });

        $html = $this->actingAs(User::factory()->create())->get('/account')->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<b>9</b>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_a_throwing_filter_keeps_the_core_navigation(): void
    {
        add_filter('cms.account.navigation_items', static function (): array {
            throw new \RuntimeException('plugin navigation failed');
        });

        $this->assertSame(self::CORE_KEYS, $this->keys());
        $this->actingAs(User::factory()->create())->get('/account')->assertOk();
    }

    public function test_a_filter_can_still_add_and_order_its_own_items(): void
    {
        add_filter('cms.account.navigation_items', static function (array $items): array {
            $items[] = ['key' => 'wishlist', 'label' => 'Wishlist', 'url' => '/account/wishlist', 'priority' => 15];
            $items[] = new AccountNavItem('orders', 'Orders', '/account/orders', 'box', 60, permission: null, badge: '3');

            return $items;
        });

        $items = app('cms.account')->navigationItems(User::factory()->create());

        $this->assertSame(['dashboard', 'wishlist', 'profile', 'security', 'sessions', 'preferences', 'orders'], array_map(static fn ($i) => $i->key, $items));
        $this->assertSame('3', collect($items)->firstWhere('key', 'orders')->badge);
    }
}
