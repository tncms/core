<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\FrontPage;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-P11-1 — saving Settings → Reading through the real admin page drives
 * the public homepage, the front-page redirect and the sitemap partition at
 * once (cache invalidated by the save), and a kept-but-inactive static page
 * id has no public effect outside static mode.
 */
final class ReadingSettingsFrontendTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    private const THEME = 'p11_reading_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();

        $dir = base_path('themes/'.self::THEME);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }
        File::put($dir.'/views/layouts/master.blade.php', 'MASTER');
        File::put($dir.'/views/pages/page.blade.php', 'PAGE-{{ $content->id ?? "" }}');
        File::put($dir.'/views/pages/home.blade.php', 'HOME-LATEST {{ isset($posts) ? $posts->count() : "x" }}');
        File::put($dir.'/views/posts/post.blade.php', 'POST');
        File::put($dir.'/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir.'/theme.json', (string) json_encode(['name' => 'P11', 'slug' => self::THEME, 'version' => '1.0.0', 'author' => 't']));
        $this->assertTrue(app('cms.theme')->activate(self::THEME));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('themes/'.self::THEME));
        File::deleteDirectory(public_path('themes/'.self::THEME));
        parent::tearDown();
    }

    private function saveReading(array $set): void
    {
        $this->actingAs(User::factory()->create());
        $t = Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Reading Test')
            ->set('data.writing_default_category_id', null);
        foreach ($set as $k => $v) {
            $t->set('data.'.$k, $v);
        }
        $t->call('save')->assertHasNoErrors();
        auth()->logout();
    }

    public function test_mode_switches_through_the_admin_page_drive_the_public_homepage(): void
    {
        $this->smPost('Bai moi');
        $static = $this->page();

        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $static->id]);
        $this->get('/')->assertOk()->assertSee('PAGE-'.$static->id, false);
        $this->get('/en')->assertOk()->assertSee('PAGE-'.$static->id, false);
        $this->get('/trang-tinh')->assertStatus(301)->assertHeader('Location', '/');
        $this->get('/en/trang-tinh-en')->assertStatus(301)->assertHeader('Location', '/en');
        $this->assertSame($static->id, FrontPage::renderedAtRootId());

        // Latest posts: the stored page id is kept but has no public effect.
        $this->saveReading(['reading_homepage_display' => 'latest_posts']);
        $this->assertSame($static->id, (int) app('cms.settings')->get('reading.homepage_page_id'));
        $this->get('/')->assertOk()->assertSee('HOME-LATEST 1', false);
        $this->get('/trang-tinh')->assertOk()->assertSee('PAGE-'.$static->id, false);
        $this->assertNull(FrontPage::renderedAtRootId());
        $this->assertContains('http://localhost/trang-tinh', $this->allLocs());

        // Back to static without re-choosing: the original page is the home again.
        $this->saveReading(['reading_homepage_display' => 'static_page']);
        $this->get('/')->assertOk()->assertSee('PAGE-'.$static->id, false);
        $this->get('/trang-tinh')->assertStatus(301);
        $this->assertNotContains('http://localhost/trang-tinh', $this->allLocs());
    }

    public function test_a_draft_static_choice_falls_back_and_is_never_published(): void
    {
        $draft = app('cms.content')->create([
            'type' => 'page', 'status' => 'draft', 'locale' => 'vi', 'title' => 'Nhap', 'slug' => 'nhap', 'content' => '<p>x</p>',
        ], \Tests\Support\ContentFixtures::context());

        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $draft->id]);

        $this->assertSame($draft->id, (int) app('cms.settings')->get('reading.homepage_page_id'));
        $this->get('/')->assertOk()->assertDontSee('PAGE-'.$draft->id, false);
        $this->get('/nhap')->assertNotFound();
        $this->assertSame('draft', Content::query()->whereKey($draft->id)->value('status'));
    }

    public function test_a_deleted_static_choice_falls_back_without_error(): void
    {
        $static = $this->page();
        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $static->id]);
        $static->delete();

        $this->get('/')->assertOk()->assertDontSee('PAGE-'.$static->id, false);
        $this->assertNull(FrontPage::renderedAtRootId());
    }

    public function test_switching_the_settings_language_does_not_touch_reading_settings(): void
    {
        $static = $this->page();
        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $static->id]);

        $this->actingAs(User::factory()->create());
        Livewire::test(SettingsPage::class)
            ->set('data.settings_locale', 'en')
            ->assertSet('data.reading_homepage_display', 'static_page')
            ->assertSet('data.reading_homepage_page_id', $static->id)
            ->set('data.general_site_name', 'Reading Test EN')
            ->set('data.writing_default_category_id', null)
            ->call('save')
            ->assertHasNoErrors();

        app('cms.settings')->clearCache();
        $this->assertSame('static_page', app('cms.settings')->get('reading.homepage_display'));
        $this->assertSame($static->id, (int) app('cms.settings')->get('reading.homepage_page_id'));
    }

    public function test_a_failed_validation_persists_nothing(): void
    {
        $static = $this->page();
        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $static->id, 'reading_posts_per_page' => 10]);

        $this->actingAs(User::factory()->create());
        Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Reading Test')
            ->set('data.writing_default_category_id', null)
            ->set('data.reading_posts_per_page', 25)
            ->set('data.reading_homepage_display', 'latest_posts')
            ->set('data.permalink_post_base', 'admin')   // reserved: rejected after field validation
            ->call('save');

        app('cms.settings')->clearCache();
        $this->assertSame(10, (int) app('cms.settings')->get('reading.posts_per_page'));
        $this->assertSame('static_page', app('cms.settings')->get('reading.homepage_display'));
    }

    public function test_the_unavailable_stored_page_is_offered_and_can_be_cleared(): void
    {
        $static = $this->page();
        $this->saveReading(['reading_homepage_display' => 'static_page', 'reading_homepage_page_id' => $static->id]);
        $static->delete();

        $this->actingAs(User::factory()->create());
        Livewire::test(SettingsPage::class)
            ->assertSet('data.reading_homepage_page_id', $static->id)
            ->assertSee('#'.$static->id)
            ->set('data.general_site_name', 'Reading Test')
            ->set('data.writing_default_category_id', null)
            ->set('data.reading_homepage_page_id', null)
            ->call('save')
            ->assertHasNoErrors();

        app('cms.settings')->clearCache();
        $this->assertSame(0, (int) app('cms.settings')->get('reading.homepage_page_id'));
    }

    public function test_settings_requires_the_settings_permission(): void
    {
        $this->get('/admin/settings')->assertRedirect();
        $this->assertFalse(SettingsPage::canAccess());
    }

    private function page(): Content
    {
        return $this->smPost('Trang tinh', true, [], 'page');
    }
}
