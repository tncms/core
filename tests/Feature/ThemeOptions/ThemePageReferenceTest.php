<?php

declare(strict_types=1);

namespace Tests\Feature\ThemeOptions;

use App\Filament\Admin\Pages\ThemeOptionsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\ThemePageReferenceResolver;

/**
 * CORE-THEME-PAGE-REFERENCE-1 — the public Page-reference contract for Theme
 * Options: a `page` field stores a stable page id, and `theme_page_url()` / the
 * resolver turn it into the page's CURRENT published, locale-aware public URL (or
 * null), never a broken/preview/admin link. Uses a disposable fixture theme so no
 * tracked theme asset is published or mutated.
 */
final class ThemePageReferenceTest extends TestCase
{
    use RefreshDatabase;

    private const THEME = 'tpr_fixture';

    private const KEY = 'privacy_policy_page';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class); // vi (default) + en

        $this->actingAs(User::query()->create([
            'name' => 'Admin', 'email' => 'tpr-admin@example.test', 'password' => bcrypt('secret-password'),
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function resolver(): ThemePageReferenceResolver
    {
        return app('cms.theme_page_reference');
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    /** A published page with distinct VI (default) + EN translations/slugs. */
    private function makePage(string $marker, string $status = 'published'): Content
    {
        $page = $this->contents()->create([
            'type' => 'page', 'status' => $status, 'locale' => 'vi',
            'title' => 'VI '.$marker, 'slug' => 'vi-'.$marker, 'content' => '<p>vi</p>',
        ]);

        return $this->contents()->update($page, [
            'locale' => 'en', 'title' => 'EN '.$marker, 'slug' => 'en-'.$marker, 'content' => '<p>en</p>',
        ]);
    }

    // ---------------------------------------------------------------------
    // Resolver URL logic (pure id + locale; no theme needed)
    // ---------------------------------------------------------------------

    public function test_published_page_resolves_default_locale_unprefixed_url(): void
    {
        $page = $this->makePage('home');

        $url = $this->resolver()->urlForId($page->id, 'vi');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('http', $url);
        $this->assertStringContainsString('/vi-home', $url);
        // Default locale is unprefixed — no /vi/ segment.
        $this->assertStringNotContainsString('/vi/', $url);
    }

    public function test_published_page_resolves_non_default_locale_prefixed_url(): void
    {
        $page = $this->makePage('about');

        $url = $this->resolver()->urlForId($page->id, 'en');

        $this->assertNotNull($url);
        $this->assertStringContainsString('/en/en-about', $url);
    }

    public function test_url_follows_current_slug_after_slug_change_without_touching_option(): void
    {
        $page = $this->makePage('slug');

        $this->contents()->update($page, ['locale' => 'vi', 'title' => 'VI slug', 'slug' => 'vi-slug-renamed']);

        $url = $this->resolver()->urlForId($page->id, 'vi');
        $this->assertStringContainsString('/vi-slug-renamed', $url);
    }

    public function test_missing_translation_in_locale_resolves_null(): void
    {
        // vi-only page: no English translation/slug.
        $page = $this->contents()->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Chỉ VI', 'slug' => 'chi-vi', 'content' => '<p>vi</p>',
        ]);

        $this->assertNotNull($this->resolver()->urlForId($page->id, 'vi'));
        $this->assertNull($this->resolver()->urlForId($page->id, 'en'));
    }

    /**
     * @dataProvider nonPublicStatuses
     */
    public function test_non_published_status_resolves_null(string $status): void
    {
        $page = $this->makePage('np', $status);
        $this->assertNull($this->resolver()->urlForId($page->id, 'vi'));
    }

    public static function nonPublicStatuses(): array
    {
        return [['draft'], ['pending'], ['private']];
    }

    public function test_soft_deleted_page_resolves_null_and_republish_recovers(): void
    {
        $page = $this->makePage('soft');
        $this->assertNotNull($this->resolver()->urlForId($page->id, 'vi'));

        $page->delete(); // soft delete
        $this->assertNull($this->resolver()->urlForId($page->id, 'vi'));

        $page->restore();
        $this->assertNotNull($this->resolver()->urlForId($page->id, 'vi'));
    }

    public function test_hard_deleted_missing_and_wrong_type_resolve_null(): void
    {
        $page = $this->makePage('hard');
        $id = $page->id;
        $page->forceDelete();
        $this->assertNull($this->resolver()->urlForId($id, 'vi'));

        // Never-existed id.
        $this->assertNull($this->resolver()->urlForId(999999, 'vi'));

        // Wrong type: a post id must not resolve to a URL.
        $post = $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => 'Bài', 'slug' => 'bai', 'content' => '<p>x</p>',
        ]);
        $this->assertNull($this->resolver()->urlForId($post->id, 'vi'));
    }

    public function test_malformed_persisted_value_never_throws(): void
    {
        $this->assertNull($this->resolver()->normalizeId('not-a-number'));
        $this->assertNull($this->resolver()->normalizeId(''));
        $this->assertNull($this->resolver()->normalizeId(-5));
        $this->assertNull($this->resolver()->normalizeId(null));
        $this->assertSame(42, $this->resolver()->normalizeId('42'));
        $this->assertSame(42, $this->resolver()->normalizeId(42));
    }

    public function test_is_persistable_policy(): void
    {
        $page = $this->makePage('persist');
        $post = $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => 'P', 'slug' => 'p-post', 'content' => '<p>x</p>',
        ]);

        $r = $this->resolver();
        $this->assertTrue($r->isPersistable(null));              // unset
        $this->assertTrue($r->isPersistable(''));                // unset
        $this->assertTrue($r->isPersistable($page->id));         // page
        $this->assertTrue($r->isPersistable((string) $page->id));
        $this->assertTrue($r->isPersistable(999999));            // missing id — preserve
        $this->assertFalse($r->isPersistable('abc'));            // malformed
        $this->assertFalse($r->isPersistable($post->id));        // wrong type (post)
    }

    public function test_draft_page_resolves_no_preview_or_signed_url(): void
    {
        $page = $this->makePage('preview', 'draft');
        $url = $this->resolver()->urlForId($page->id, 'vi');
        $this->assertNull($url); // never a signed/preview link
    }

    public function test_resolution_cost_is_bounded_regardless_of_page_count(): void
    {
        $target = $this->makePage('bounded');

        // Warm any one-off boot queries, then measure a single resolution.
        $this->resolver()->urlForId($target->id, 'vi');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver()->urlForId($target->id, 'vi');
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Add many more pages; one resolution must cost the SAME (indexed PK
        // lookup + the URL service) — never a full-table load / N+1 over pages.
        foreach (range(1, 25) as $i) {
            $this->makePage('extra'.$i);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->resolver()->urlForId($target->id, 'vi');
        $withManyPages = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baseline, $withManyPages, 'Resolution query count must not scale with the number of pages.');
    }

    // ---------------------------------------------------------------------
    // Theme-option integration + Filament picker (fixture theme)
    // ---------------------------------------------------------------------

    public function test_page_field_type_is_recognized_in_schema(): void
    {
        $this->activateFixture();

        $schema = app('cms.theme_option')->schema(self::THEME);
        $fields = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $fields[$field['key']] = $field['type'];
            }
        }

        $this->assertArrayHasKey(self::KEY, $fields);
        $this->assertSame('page', $fields[self::KEY]); // not skipped as unsupported
    }

    public function test_picker_renders_and_persists_selected_page_id(): void
    {
        $this->activateFixture();
        $page = $this->makePage('picked');

        Livewire::test(ThemeOptionsPage::class)
            ->assertOk()
            ->set('data.'.self::KEY, (string) $page->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals($page->id, app('cms.theme_option')->get(self::KEY, null, self::THEME));
        $this->assertTrue(settings()->has('theme_options.'.self::THEME.'.'.self::KEY));
    }

    public function test_url_resolves_through_theme_option_helper(): void
    {
        $this->activateFixture();
        $page = $this->makePage('viahelper');
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);

        // Active theme is the fixture → theme_page_url reads its option namespace.
        $this->assertStringContainsString('/vi-viahelper', (string) theme_page_url(self::KEY, 'vi'));
        $this->assertStringContainsString('/en/en-viahelper', (string) theme_page_url(self::KEY, 'en'));
    }

    public function test_empty_selection_is_allowed_and_resolves_null(): void
    {
        $this->activateFixture();

        Livewire::test(ThemeOptionsPage::class)
            ->set('data.'.self::KEY, null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(theme_page_url(self::KEY, 'vi'));
    }

    public function test_wrong_type_and_malformed_selection_rejected_on_save(): void
    {
        $this->activateFixture();
        $post = $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'vi',
            'title' => 'P', 'slug' => 'p-wrong', 'content' => '<p>x</p>',
        ]);

        // Wrong type (a post id) rejected server-side (direct Livewire set).
        Livewire::test(ThemeOptionsPage::class)
            ->set('data.'.self::KEY, (string) $post->id)
            ->call('save')
            ->assertHasErrors('data.'.self::KEY);

        // Malformed rejected too.
        Livewire::test(ThemeOptionsPage::class)
            ->set('data.'.self::KEY, 'not-an-id')
            ->call('save')
            ->assertHasErrors('data.'.self::KEY);

        $this->assertFalse(settings()->has('theme_options.'.self::THEME.'.'.self::KEY));
    }

    public function test_unavailable_id_preserved_on_unrelated_save(): void
    {
        $this->activateFixture();
        $page = $this->makePage('preserve');
        app('cms.theme_option')->set(self::KEY, $page->id, self::THEME);

        // Page becomes unavailable (soft-deleted) → no public URL...
        $page->delete();
        $this->assertNull(theme_page_url(self::KEY, 'vi'));

        // ...but an unrelated Theme Options save must NOT drop the stored id.
        Livewire::test(ThemeOptionsPage::class)
            ->set('data.'.\TheNguyen\CMS\Services\ThemeCustomCssManager::FRONTEND_KEY, '.x{color:red}')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals($page->id, app('cms.theme_option')->get(self::KEY, null, self::THEME));

        // Restoring the page recovers the link without reselecting.
        $page->restore();
        $this->assertNotNull(theme_page_url(self::KEY, 'vi'));
    }

    public function test_picker_search_is_bounded(): void
    {
        $this->activateFixture();
        foreach (range(1, 5) as $i) {
            $this->makePage('search'.$i);
        }

        $results = Livewire::test(ThemeOptionsPage::class)
            ->instance();

        // The page search helper caps results (bounded) and only returns pages.
        $ref = new \ReflectionMethod($results, 'pageSearchResults');
        $ref->setAccessible(true);
        $found = $ref->invoke($results, 'search');
        $this->assertLessThanOrEqual(50, count($found));
        $this->assertNotEmpty($found);
    }

    // --- fixture -------------------------------------------------------------

    private function activateFixture(): void
    {
        $dir = base_path('themes/'.self::THEME);

        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::ensureDirectoryExists($dir.'/assets/css');
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/assets/css/main.css', '/*tpr*/');
        File::put($dir.'/theme.json', json_encode([
            'name' => 'TPR Fixture', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't',
            'assets' => [['handle' => 'tpr-css', 'src' => 'css/main.css', 'primary' => true]],
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
