<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-P11-1 — Settings → Reading persistence contract.
 *
 * A field hidden by the form (homepage / posts page outside static mode,
 * maintenance page outside page mode, retry-after outside 503) is OMITTED,
 * not cleared: saving keeps the stored value. A visible field the admin
 * empties is an explicit clear. Invalid ids never replace stored ones.
 */
final class ReadingSettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        $this->actingAs(User::factory()->create());
    }

    private function page(string $title): Content
    {
        return $this->smPost($title, true, [], 'page');
    }

    /** A valid Settings form: fields that are not under test but must validate. */
    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(SettingsPage::class)
            ->set('data.general_site_name', 'Reading Test')
            ->set('data.writing_default_category_id', null);
    }

    private function stored(string $key): mixed
    {
        app('cms.settings')->clearCache();

        return app('cms.settings')->get($key);
    }

    private function useStatic(?Content $home, ?Content $posts = null): void
    {
        $s = app('cms.settings');
        $s->set('reading.homepage_display', 'static_page', 'string');
        $s->set('reading.homepage_page_id', $home?->id ?? 0, 'integer');
        $s->set('reading.posts_page_id', $posts?->id ?? 0, 'integer');
        $s->clearCache();
    }

    public function test_switching_to_latest_posts_keeps_the_saved_page_selections(): void
    {
        $home = $this->page('Trang tinh');
        $blog = $this->page('Tin tuc');
        $this->useStatic($home, $blog);

        $this->form()
            ->set('data.reading_homepage_display', 'latest_posts')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('latest_posts', $this->stored('reading.homepage_display'));
        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
        $this->assertSame($blog->id, (int) $this->stored('reading.posts_page_id'));
    }

    public function test_switching_back_to_static_restores_the_original_selection(): void
    {
        $home = $this->page('Trang tinh');
        $blog = $this->page('Tin tuc');
        $this->useStatic($home, $blog);

        $this->form()->set('data.reading_homepage_display', 'latest_posts')->call('save')->assertHasNoErrors();

        // Reopen the form: the static selections are offered again unchanged.
        $this->form()
            ->set('data.reading_homepage_display', 'static_page')
            ->assertSet('data.reading_homepage_page_id', $home->id)
            ->assertSet('data.reading_posts_page_id', $blog->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('static_page', $this->stored('reading.homepage_display'));
        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
        $this->assertSame($blog->id, (int) $this->stored('reading.posts_page_id'));
    }

    public function test_the_default_legacy_mode_also_keeps_the_selections(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);

        $this->form()->set('data.reading_homepage_display', null)->call('save')->assertHasNoErrors();

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_repeated_saves_in_latest_posts_mode_are_idempotent(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);

        for ($i = 0; $i < 3; $i++) {
            $this->form()->set('data.reading_homepage_display', 'latest_posts')->call('save')->assertHasNoErrors();
        }

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_an_explicitly_cleared_visible_selection_is_cleared(): void
    {
        $home = $this->page('Trang tinh');
        $blog = $this->page('Tin tuc');
        $this->useStatic($home, $blog);

        $this->form()
            ->set('data.reading_posts_page_id', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
        $this->assertSame(0, (int) $this->stored('reading.posts_page_id'));
    }

    public function test_static_mode_with_no_posts_page_saves_without_choosing_one(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);   // posts_page_id stored as 0 (seeded default)

        $this->form()->call('save')->assertHasNoErrors();

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
        $this->assertSame(0, (int) $this->stored('reading.posts_page_id'));
    }

    public function test_an_unknown_page_id_is_rejected_and_the_stored_id_kept(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);

        $this->form()
            ->set('data.reading_homepage_page_id', 999999)
            ->call('save')
            ->assertHasErrors(['data.reading_homepage_page_id']);

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_a_post_id_is_not_accepted_as_a_page(): void
    {
        $home = $this->page('Trang tinh');
        $post = $this->smPost('Bai viet');
        $this->useStatic($home);

        $this->form()
            ->set('data.reading_homepage_page_id', $post->id)
            ->call('save')
            ->assertHasErrors(['data.reading_homepage_page_id']);

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_a_value_injected_into_a_hidden_field_is_not_persisted(): void
    {
        $home = $this->page('Trang tinh');
        $other = $this->page('Khac');
        $this->useStatic($home);
        app('cms.settings')->set('reading.homepage_display', 'latest_posts', 'string');
        app('cms.settings')->clearCache();

        // Crafted state for a field the form hides in latest-posts mode.
        $this->form()
            ->set('data.reading_homepage_page_id', $other->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_a_deleted_selected_page_does_not_block_saving_other_settings(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);
        $home->delete();

        $this->form()
            ->set('data.reading_posts_per_page', 7)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7, (int) $this->stored('reading.posts_per_page'));
        // The admin did not change the selection; it is kept for them to fix.
        $this->assertSame($home->id, (int) $this->stored('reading.homepage_page_id'));
    }

    public function test_maintenance_page_survives_switching_to_theme_mode(): void
    {
        $page = $this->page('Bao tri');
        $s = app('cms.settings');
        $s->set('maintenance.mode', 'page', 'string');
        $s->set('maintenance.page_id', $page->id, 'integer');
        $s->set('maintenance.status_code', 503, 'integer');
        $s->set('maintenance.retry_after_minutes', 45, 'integer');
        $s->clearCache();

        $this->form()
            ->set('data.maintenance_mode', 'theme')
            ->set('data.maintenance_status_code', 200)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('theme', $this->stored('maintenance.mode'));
        $this->assertSame($page->id, (int) $this->stored('maintenance.page_id'));
        $this->assertSame(45, (int) $this->stored('maintenance.retry_after_minutes'));
    }

    public function test_the_seeded_no_default_category_does_not_block_saving(): void
    {
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'Tin chung']);
        app('cms.settings')->set('writing.default_category_id', 0, 'integer');   // seeded default
        app('cms.settings')->clearCache();

        // The stock form, as an admin opens it on a fresh install: only the
        // localized site name (empty until translated in tests) is filled in.
        Livewire::test(SettingsPage::class)
            ->assertSet('data.writing_default_category_id', null)
            ->set('data.general_site_name', 'Reading Test')
            ->set('data.reading_homepage_display', 'latest_posts')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('latest_posts', $this->stored('reading.homepage_display'));
        $this->assertSame(0, (int) $this->stored('writing.default_category_id'));
    }

    public function test_unrelated_settings_are_unchanged_by_a_mode_switch(): void
    {
        $home = $this->page('Trang tinh');
        $this->useStatic($home);
        app('cms.settings')->set('reading.posts_per_page', 13, 'integer');
        app('cms.settings')->set('seo.robots_default', 'index,follow', 'string');
        app('cms.settings')->clearCache();

        $this->form()->set('data.reading_homepage_display', 'latest_posts')->call('save')->assertHasNoErrors();

        $this->assertSame(13, (int) $this->stored('reading.posts_per_page'));
        $this->assertSame('index,follow', $this->stored('seo.robots_default'));
    }
}
