<?php

declare(strict_types=1);

namespace Tests\Feature\Integration;

use App\Filament\Admin\Resources\PageResource\Pages\ListPages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

/**
 * CORE 7.1.32 PRE-RELEASE INTEGRATION R2 — three-contract coexistence.
 *
 * R1 already proves Content Admin UX bulk actions × Theme Page Reference. This
 * suite adds the NEW interactions introduced by the third contract — the Unified
 * Frontend System View Resolver — against the other two in the SAME integrated
 * build and the SAME Filament admin:
 *
 *  - the six frozen system-page mappings survive integration (exact fallbacks,
 *    fixed allowlist, unknown key rejected — no user-supplied view paths);
 *  - bulk-drafting a Theme-Option-referenced page nulls theme_page_url yet does
 *    NOT disturb system-page view resolution (login theme override still wins);
 *  - the fixture theme that overrides theme::auth.login AND omits a robots meta
 *    still yields X-Robots-Tag: noindex,nofollow on auth/account, while Search
 *    keeps its noindex,follow meta with no header;
 *  - a broken login theme override falls back to the Core login and stays
 *    reachable in the integrated app;
 *  - the Theme Options page picker stays safe for wrong-type / missing ids while
 *    the resolver is active.
 */
final class ResolverThreeContractCoexistenceR2Test extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'r2coexist_fixture';

    private const KEY = 'privacy_policy_page';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        $this->activateFixture();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin', 'email' => 'r2-admin@example.test', 'password' => bcrypt('secret-password'),
        ]);
    }

    private function makePublishedPage(): Content
    {
        $page = $this->contents()->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Chính sách', 'slug' => 'chinh-sach', 'content' => '<p>vi</p>',
        ]);

        return $this->contents()->update($page, [
            'locale' => 'en', 'title' => 'Privacy', 'slug' => 'privacy', 'content' => '<p>en</p>',
        ]);
    }

    // 1. The resolver registry is intact and frozen in the integrated build.
    public function test_nine_mappings_and_allowlist_survive_integration(): void
    {
        $r = app('cms.system_view');

        $this->assertSame(
            [
                'login', 'register', 'forgot-password', 'reset-password',
                'account', 'profile', 'security', 'preferences', 'sessions', 'search',
            ],
            $r->pages(),
        );
        // No theme override present → exact frozen fallbacks (search = host view).
        View::replaceNamespace('theme', [base_path('themes/__none__')]);
        $this->assertSame('cms::auth.login', $r->resolve('login'));
        $this->assertSame('cms::account.dashboard', $r->resolve('account'));
        $this->assertSame('cms::account.profile', $r->resolve('profile'));
        $this->assertSame('cms::account.security', $r->resolve('security'));
        $this->assertSame('cms::account.preferences', $r->resolve('preferences'));
        $this->assertSame('frontend.search.index', $r->resolve('search'));

        // Fixed allowlist: no caller/user input can select an arbitrary view.
        $this->expectException(InvalidArgumentException::class);
        $r->resolve('../../etc/passwd');
    }

    // 2. Cross-contract: an admin bulk-draft of a referenced page nulls the theme
    //    link but leaves system-page view resolution (login override) untouched.
    public function test_bulk_draft_referenced_page_nulls_link_but_not_system_page_resolution(): void
    {
        $page = $this->makePublishedPage();
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);
        $this->useFixtureViews();

        // Baseline: theme link resolves AND the login theme override is selected.
        $this->assertStringContainsString('/chinh-sach', (string) theme_page_url(self::KEY, 'vi'));
        $this->assertSame('theme::auth.login', app('cms.system_view')->resolve('login'));

        // Content Admin UX bulk-draft of the referenced page.
        $this->actingAs($this->admin());
        Livewire::test(ListPages::class)
            ->callTableBulkAction('changeStatus', [$page->id], ['status' => 'draft'])
            ->assertHasNoTableBulkActionErrors();

        // Theme Page Reference: link nulls; stored id intact.
        $this->assertNull(theme_page_url(self::KEY, 'vi'));
        $this->assertEquals($page->id, app('cms.theme_option')->get(self::KEY, null, self::THEME));

        // Resolver is UNAFFECTED by the content lifecycle change.
        $this->assertSame('theme::auth.login', app('cms.system_view')->resolve('login'));
    }

    // 3. HTTP: auth/account noindex via header even though the fixture login layout
    //    omits the robots meta; Search keeps noindex,follow meta and no header.
    public function test_noindex_header_enforced_with_theme_override_lacking_meta(): void
    {
        $this->useFixtureViews();

        $login = $this->get('/login');
        $login->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('r2-fixture-login', false)   // theme override won
            ->assertDontSee('name="robots"', false); // fixture layout omits the meta

        $this->get('/register')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $account = $this->actingAs($this->admin())->get('/account');
        $account->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        // Search is a utility page: the resolver adds NO X-Robots-Tag header
        // (unlike auth/account). Its noindex,follow is theme-rendered meta, so
        // assert it against the default theme (which ships partials/seo); the
        // fixture's minimal layout intentionally has no SEO partial.
        View::replaceNamespace('theme', [base_path('themes/default/views')]);
        $search = $this->get('/search?q=x');
        $search->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertSee('content="noindex,follow"', false);
    }

    // 4. A broken login theme override must not take login offline (recursion-safe
    //    fallback to the Core login) in the integrated app.
    public function test_broken_login_override_falls_back_to_core_login(): void
    {
        // Point the theme namespace at the committed broken fixture.
        View::replaceNamespace('theme', [base_path('tests/Fixtures/system-view-theme-broken')]);
        $this->assertTrue(View::exists('theme::auth.login'));

        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('tn-field', false)             // Core fallback rendered
            ->assertDontSee('should-never-render', false);
    }

    // 5. Theme Options page picker stays safe for a wrong-type id while the
    //    resolver + admin features are all loaded.
    public function test_page_picker_safe_for_wrong_type_id(): void
    {
        // A POST (not a page) stored as the page reference must not fabricate a URL.
        $post = $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Bài viết', 'slug' => 'bai-viet', 'content' => '<p>p</p>',
        ]);
        app('cms.theme_option')->set(self::KEY, $post->id, self::THEME);

        $this->assertNull(theme_page_url(self::KEY, 'vi'));
        $this->assertNull(theme_page_url(self::KEY, 'en'));
    }

    // --- fixtures -----------------------------------------------------------

    /** Make the fixture theme's views the active "theme::" authority. */
    private function useFixtureViews(): void
    {
        View::replaceNamespace('theme', [base_path('themes/'.self::THEME.'/views')]);
    }

    private function activateFixture(): void
    {
        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives', 'auth'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', '<!doctype html><html><head><title>fx</title></head><body>@yield("content")</body></html>');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        // Login override: distinct marker, a working form, and NO robots meta
        // (self-contained layout) so the X-Robots-Tag header is what enforces noindex.
        File::put($dir.'/views/auth/login.blade.php',
            '<!doctype html><html><head><title>Login</title></head><body>'
            .'<h1 data-marker="r2-fixture-login">r2-fixture-login</h1>'
            .'<form method="post" action="{{ route(\'cms.auth.login.attempt\') }}">@csrf'
            .'<input name="email" value="{{ old(\'email\') }}"><input type="password" name="password">'
            .'<button type="submit">Sign in</button></form></body></html>');
        File::put($dir.'/theme.json', json_encode([
            'name' => 'R2 Coexist Fixture', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't',
        ]));
        File::put($dir.'/theme.options.json', json_encode(['options' => ['sections' => [
            ['key' => 'advanced', 'group' => 'advanced', 'label' => 'Advanced', 'fields' => [
                ['key' => self::KEY, 'label' => 'Privacy Policy Page', 'type' => 'page'],
            ]],
        ]]]));
        app('cms.theme')->activate(self::THEME);
        $this->assertSame(self::THEME, app('cms.theme')->active()?->slug);
    }
}
