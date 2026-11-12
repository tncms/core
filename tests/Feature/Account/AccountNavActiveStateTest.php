<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;
use TheNguyen\CMS\Support\Account\AccountNavItem;

/**
 * CORE-40-ACCOUNT-SHELL-LOCALE-R1 — account navigation active state: an item is active only
 * when its URL (absolute or root-relative) is the current URL on this host, ignoring the
 * current request's query string. No prefix matching, so no false positives.
 */
final class AccountNavActiveStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
    }

    /** @param  list<AccountNavItem>  $extra
     *  @return list<string> keys of the active items */
    private function activeAt(string $url, array $extra = []): array
    {
        $this->app->instance('request', Request::create($url));
        app('cms.language')->setCurrent(str_contains($url, '/en/') ? 'en' : 'vi');
        add_filter('cms.account.navigation_items', static fn (array $items): array => array_merge($items, $extra));

        $items = app('cms.account')->navigationItems(User::factory()->create(), request());

        return array_values(array_map(static fn ($i) => $i->key, array_filter($items, static fn ($i) => $i->active)));
    }

    public function test_exact_match_marks_only_that_item(): void
    {
        $this->assertSame(['profile'], $this->activeAt('http://localhost/account/profile'));
    }

    public function test_dashboard_is_not_active_on_a_nested_account_page(): void
    {
        $this->assertSame(['security'], $this->activeAt('http://localhost/account/security'));
        $this->assertSame([], $this->activeAt('http://localhost/account/profile/extra'));
    }

    public function test_a_plugin_section_item_is_not_active_on_its_nested_pages(): void
    {
        $articles = new AccountNavItem('articles', 'Articles', '/account/articles', 'file', 60);
        $this->assertSame(['articles'], $this->activeAt('http://localhost/account/articles', [$articles]));
        $this->assertSame([], $this->activeAt('http://localhost/account/articles/5/edit', [$articles]));
        $this->assertSame([], $this->activeAt('http://localhost/account/articles-archive', [$articles]));
    }

    public function test_the_current_query_string_does_not_prevent_the_match(): void
    {
        $this->assertSame(['sessions'], $this->activeAt('http://localhost/account/sessions?page=2&sort=asc'));
    }

    public function test_an_item_url_with_a_query_string_is_not_matched_by_its_path(): void
    {
        $tab = new AccountNavItem('tab', 'Tab', '/account/profile?tab=bio', 'doc', 60);
        $this->assertSame(['profile'], $this->activeAt('http://localhost/account/profile?tab=bio', [$tab]));
    }

    public function test_locale_prefixed_urls_match_their_own_locale_only(): void
    {
        $this->assertSame(['profile'], $this->activeAt('http://localhost/en/account/profile'));

        $unprefixed = new AccountNavItem('legacy', 'Legacy', '/account/profile', 'doc', 60);
        $prefixed = new AccountNavItem('localized', 'Localized', '/en/account/profile', 'doc', 61);
        $this->assertSame(['profile', 'localized'], $this->activeAt('http://localhost/en/account/profile', [$unprefixed, $prefixed]));
    }

    public function test_absolute_and_root_relative_forms_match_alike(): void
    {
        $abs = new AccountNavItem('abs', 'Abs', 'http://localhost/account/preferences/', 'doc', 60);
        $rel = new AccountNavItem('rel', 'Rel', '/account/preferences', 'doc', 61);
        $this->assertSame(['preferences', 'abs', 'rel'], $this->activeAt('http://localhost/account/preferences', [$abs, $rel]));
    }

    public function test_another_host_or_empty_url_is_never_active(): void
    {
        $foreign = new AccountNavItem('foreign', 'Foreign', 'https://evil.test/account/profile', 'doc', 60);
        $empty = new AccountNavItem('empty', 'Empty', '', 'doc', 61);
        $this->assertSame(['profile'], $this->activeAt('http://localhost/account/profile', [$foreign, $empty]));
    }
}
