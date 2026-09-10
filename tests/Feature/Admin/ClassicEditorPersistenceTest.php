<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\PageResource\Pages\CreatePage;
use App\Filament\Admin\Resources\PageResource\Pages\EditPage;
use App\Filament\Admin\Resources\PostResource\Pages\CreatePost;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;

/**
 * CORE-EDITOR-1B — Classic Editor content persistence matrix (§20 five-point
 * proof, server half): the value present in Livewire/Filament form state MUST
 * be the value the server mutates, the DB persists, and a fresh mount
 * reloads — for Posts AND Pages, create AND edit, EN AND VI — without
 * touching unrelated fields or the other locale's translation.
 */
final class ClassicEditorPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Baseline languages (vi default + en) so the locale select accepts
        // both authoring locales, exactly like an installed site.
        $this->seed(CmsLanguageSeeder::class);

        $this->actingAs(User::factory()->create());
    }

    private function contents(): ContentManager
    {
        return app('cms.content');
    }

    /** Bilingual published post with distinct EN/VI markers. */
    private function makePost(): Content
    {
        $post = $this->contents()->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => 'CE1 Post', 'slug' => 'ce1-post',
            'excerpt' => 'CE1 excerpt EN',
            'content' => '<p>OLD_EN_v1 original content</p>',
        ]);

        return $this->contents()->update($post, [
            'locale' => 'vi', 'title' => 'CE1 Bài viết', 'slug' => 'ce1-bai-viet',
            'excerpt' => 'CE1 excerpt VI',
            'content' => '<p>OLD_VI_v1 nội dung gốc</p>',
        ]);
    }

    private function translation(Content $content, string $locale): ?string
    {
        return $content->translations()->where('locale', $locale)->value('content');
    }

    public function test_post_edit_en_persists_and_fresh_mount_reloads(): void
    {
        $post = $this->makePost();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'en'])
            ->assertSet('data.content', '<p>OLD_EN_v1 original content</p>')
            ->fillForm(['content' => '<p>NEW_EN_v2 edited content</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>NEW_EN_v2 edited content</p>', $this->translation($post, 'en'));

        // Fresh mount (new request) must show the persisted value (§20 E).
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'en'])
            ->assertSet('data.content', '<p>NEW_EN_v2 edited content</p>');

        // Translation isolation (§30): VI untouched by the EN save.
        $this->assertSame('<p>OLD_VI_v1 nội dung gốc</p>', $this->translation($post, 'vi'));
    }

    public function test_post_edit_vi_persists_and_en_is_isolated(): void
    {
        $post = $this->makePost();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'vi'])
            ->assertSet('data.content', '<p>OLD_VI_v1 nội dung gốc</p>')
            ->fillForm(['content' => '<p>NEW_VI_v2 nội dung đã sửa</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>NEW_VI_v2 nội dung đã sửa</p>', $this->translation($post, 'vi'));
        $this->assertSame('<p>OLD_EN_v1 original content</p>', $this->translation($post, 'en'));

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'vi'])
            ->assertSet('data.content', '<p>NEW_VI_v2 nội dung đã sửa</p>');
    }

    public function test_post_create_persists_content_in_each_locale(): void
    {
        foreach (['en' => '<p>CREATED_EN body</p>', 'vi' => '<p>CREATED_VI nội dung</p>'] as $locale => $html) {
            Livewire::test(CreatePost::class)
                ->fillForm([
                    'locale' => $locale,
                    'title' => "Created $locale",
                    'content' => $html,
                    'status' => 'draft',
                ])
                ->call('create')
                ->assertHasNoErrors();

            $created = Content::query()->where('type', 'post')->latest('id')->first();
            $this->assertNotNull($created);
            $this->assertSame($html, $this->translation($created, $locale));
        }
    }

    public function test_page_create_and_edit_persist_content(): void
    {
        // The Page form's Template selector reads the ACTIVE theme's declared
        // templates. Point the setting at the shipped default theme directly —
        // a full activate() would republish public/themes/default and dirty
        // the repo working tree from inside the test run.
        app('cms.settings')->set('theme.active', 'default');

        Livewire::test(CreatePage::class)
            ->fillForm([
                'locale' => 'en',
                'title' => 'CE1 Page',
                'content' => '<p>PAGE_EN_v1 body</p>',
                'status' => 'published',
            ])
            ->call('create')
            ->assertHasNoErrors();

        $page = Content::query()->where('type', 'page')->latest('id')->firstOrFail();
        $this->assertSame('<p>PAGE_EN_v1 body</p>', $this->translation($page, 'en'));

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey(), 'translationLocale' => 'en'])
            ->assertSet('data.content', '<p>PAGE_EN_v1 body</p>')
            ->fillForm(['content' => '<p>PAGE_EN_v2 edited body</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>PAGE_EN_v2 edited body</p>', $this->translation($page, 'en'));
    }

    public function test_realistic_html_survives_save_without_loss(): void
    {
        $post = $this->makePost();

        $html = "<p>Bạn có thể đạt phần lớn hiệu năng từ vài thói quen...</p>\n<p></p>\n<h2>Đo lường trước</h2>\n<p>Hãy đo bằng công cụ thật trước khi tối ưu.</p>";

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'vi'])
            ->fillForm(['content' => $html])
            ->call('save')
            ->assertHasNoErrors();

        $stored = (string) $this->translation($post, 'vi');

        // No stripping, escaping or double-encoding of structure (§24).
        $this->assertStringContainsString('<h2>Đo lường trước</h2>', $stored);
        $this->assertStringContainsString('<p>Bạn có thể đạt phần lớn hiệu năng từ vài thói quen...</p>', $stored);
        $this->assertStringContainsString('<p>Hãy đo bằng công cụ thật trước khi tối ưu.</p>', $stored);
        $this->assertStringNotContainsString('&lt;h2&gt;', $stored);
        $this->assertStringNotContainsString('&amp;lt;', $stored);
    }

    public function test_empty_and_legacy_null_semantics_never_resurrect_old_content(): void
    {
        $post = $this->makePost();

        // non-empty → empty: the cleared editor must NOT bring OLD_EN back.
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'en'])
            ->fillForm(['content' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $cleared = $this->translation($post, 'en');
        $this->assertTrue(
            $cleared === null || trim((string) $cleared) === '',
            'Clearing the editor must persist empty, got: '.var_export($cleared, true)
        );

        // empty → non-empty.
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'en'])
            ->fillForm(['content' => '<p>REFILLED_EN body</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>REFILLED_EN body</p>', $this->translation($post, 'en'));

        // legacy null translation → non-empty (edit a locale that has no row yet).
        $legacy = $this->contents()->create([
            'type' => 'post', 'status' => 'draft', 'locale' => 'en',
            'title' => 'Legacy', 'slug' => 'legacy-post', 'content' => null,
        ]);

        Livewire::test(EditPost::class, ['record' => $legacy->getRouteKey(), 'translationLocale' => 'en'])
            ->fillForm(['content' => '<p>FROM_NULL body</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('<p>FROM_NULL body</p>', $this->translation($legacy, 'en'));
    }

    public function test_saving_content_leaves_unrelated_fields_untouched(): void
    {
        $post = $this->makePost();
        $before = $post->translations()->where('locale', 'en')->first();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'en'])
            ->fillForm(['content' => '<p>ONLY_CONTENT_CHANGED</p>'])
            ->call('save')
            ->assertHasNoErrors();

        $post->refresh();
        $after = $post->translations()->where('locale', 'en')->first();

        $this->assertSame('<p>ONLY_CONTENT_CHANGED</p>', $after->content);
        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->slug, $after->slug);
        $this->assertSame($before->excerpt, $after->excerpt);
        $this->assertSame('published', $post->status);
        $this->assertFalse((bool) $post->is_featured);

        // VI translation fully preserved (§29/§30).
        $vi = $post->translations()->where('locale', 'vi')->first();
        $this->assertSame('CE1 Bài viết', $vi->title);
        $this->assertSame('<p>OLD_VI_v1 nội dung gốc</p>', $vi->content);
    }
}
