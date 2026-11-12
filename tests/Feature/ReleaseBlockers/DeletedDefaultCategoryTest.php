<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseBlockers;

use App\Filament\Admin\Pages\SettingsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-RELEASE-BLOCKERS-R1 (TD-R2): a stored writing.default_category_id that no longer names a category
 * must not block saving unrelated settings, must not be silently replaced or cleared, and must be shown
 * as unavailable. Choosing a non-existent category explicitly is still refused. Same rule as Reading's
 * page selects (CORE-P11-1).
 */
final class DeletedDefaultCategoryTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite();
        app('cms.taxonomy')->ensureCoreTaxonomies();
        $this->actingAs(User::factory()->create());
    }

    private function category(string $name): int
    {
        return (int) app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => $name])->id;
    }

    private function storeDefault(int $id): void
    {
        app('cms.settings')->set('writing.default_category_id', $id, 'integer');
        app('cms.settings')->clearCache();
    }

    private function stored(string $key): mixed
    {
        app('cms.settings')->clearCache();

        return app('cms.settings')->get($key);
    }

    /** The stock form as an admin opens it; only the required site name is filled in. */
    private function form(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(SettingsPage::class)->set('data.general_site_name', 'TD-R2');
    }

    private function deletedCategory(): int
    {
        $id = $this->category('Sap xoa');
        $this->storeDefault($id);
        app('cms.taxonomy')->deleteTerm(\TheNguyen\CMS\Models\Term::query()->findOrFail($id));

        return $id;
    }

    public function test_a_deleted_default_category_does_not_block_saving_unrelated_settings(): void
    {
        $this->category('Con lai');
        $gone = $this->deletedCategory();

        $this->form()
            ->assertSet('data.writing_default_category_id', $gone)
            ->set('data.reading_posts_per_page', 9)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(9, (int) $this->stored('reading.posts_per_page'));
        $this->assertSame($gone, (int) $this->stored('writing.default_category_id'), 'kept for the admin to fix, not replaced');
    }

    public function test_a_default_category_id_that_never_existed_does_not_block_saving(): void
    {
        $this->category('Con lai');
        $this->storeDefault(987654);

        $this->form()->set('data.reading_posts_per_page', 8)->call('save')->assertHasNoErrors();

        $this->assertSame(8, (int) $this->stored('reading.posts_per_page'));
        $this->assertSame(987654, (int) $this->stored('writing.default_category_id'));
    }

    public function test_the_missing_selection_is_labelled_unavailable(): void
    {
        $gone = $this->deletedCategory();

        // Rendered as the select's initial label (JS-encoded) in the admin's language.
        $label = tn_trans('Unavailable category #:id', ['id' => $gone]);
        $this->assertStringContainsString('#'.$gone, $label);
        $this->form()->assertSeeHtml('initialOptionLabel: '.\Illuminate\Support\Js::from($label)->toHtml());
    }

    public function test_choosing_a_different_non_existent_category_is_still_refused(): void
    {
        $gone = $this->deletedCategory();
        $valid = $this->category('Hop le');

        $this->form()
            ->set('data.writing_default_category_id', $valid + 1000)
            ->call('save')
            ->assertHasErrors(['data.writing_default_category_id']);

        $this->assertSame($gone, (int) $this->stored('writing.default_category_id'));
    }

    public function test_replacing_the_missing_category_with_a_valid_one_saves_it(): void
    {
        $this->deletedCategory();
        $valid = $this->category('Hop le');

        $this->form()->set('data.writing_default_category_id', $valid)->call('save')->assertHasNoErrors();

        $this->assertSame($valid, (int) $this->stored('writing.default_category_id'));
    }

    public function test_an_explicit_clear_stores_no_default_category(): void
    {
        $this->deletedCategory();

        $this->form()->set('data.writing_default_category_id', null)->call('save')->assertHasNoErrors();

        $this->assertSame(0, (int) $this->stored('writing.default_category_id'));
    }

    public function test_a_valid_default_category_is_unchanged_by_an_unrelated_save(): void
    {
        $valid = $this->category('Hop le');
        $this->storeDefault($valid);

        $this->form()->set('data.reading_posts_per_page', 6)->call('save')->assertHasNoErrors();

        $this->assertSame($valid, (int) $this->stored('writing.default_category_id'));
    }

    public function test_a_tag_id_is_never_accepted_as_the_default_category(): void
    {
        $tag = (int) app('cms.taxonomy')->createTerm('tag', ['locale' => 'vi', 'name' => 'The'])->id;

        $this->form()->set('data.writing_default_category_id', $tag)->call('save')
            ->assertHasErrors(['data.writing_default_category_id']);
    }

    public function test_a_stale_form_cannot_write_a_missing_category_back_over_a_fixed_one(): void
    {
        // Admin A opened the page while the stored category was missing; admin B then chose a valid one.
        // A's later save is refused on the category field (the missing id is no longer the stored one) and
        // writes nothing, instead of silently reverting B's fix. Other visible fields remain
        // last-writer-wins page-wide (TD-R1, accepted debt).
        $this->deletedCategory();
        $valid = $this->category('Hop le');
        $stale = $this->form();

        $this->form()->set('data.writing_default_category_id', $valid)->call('save')->assertHasNoErrors();

        $stale->set('data.reading_posts_per_page', 5)->call('save')->assertHasErrors(['data.writing_default_category_id']);

        $this->assertSame($valid, (int) $this->stored('writing.default_category_id'));
        $this->assertNotSame(5, (int) $this->stored('reading.posts_per_page'), 'a refused save writes nothing');
    }
}
