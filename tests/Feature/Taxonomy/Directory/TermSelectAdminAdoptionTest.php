<?php

declare(strict_types=1);

namespace Tests\Feature\Taxonomy\Directory;

use App\Filament\Admin\Resources\PostResource\Pages\CreatePost;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Filament\Forms\TermPickerOptions;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Taxonomy\Directory\TaxonomyDirectory;

/**
 * CORE-P16-1: the TermSelect adapter as adopted by the Post editor (categories). Drives the real
 * Filament/Livewire component: bounded first page, server search, label hydration of selections
 * outside the first page, server-side validation of submitted ids, save → reopen persistence,
 * strict editing-locale policy and preserved foreign-locale assignments.
 */
final class TermSelectAdminAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private const FIELD = 'form.category_ids';

    /** @var list<Term> */
    private array $categories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();

        $admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$admin->email]]);
        $this->actingAs($admin);

        // More categories than one directory page, so "beyond the first page" is real.
        for ($i = 1; $i <= TaxonomyDirectory::DEFAULT_LIMIT + 5; $i++) {
            $this->categories[] = app('cms.taxonomy')->createTerm('category', [
                'locale' => 'vi', 'name' => sprintf('Chuyên mục %02d', $i), 'sort_order' => $i,
            ]);
        }
    }

    private function last(): Term
    {
        return $this->categories[array_key_last($this->categories)];
    }

    /** @return array<int|string, string> */
    private function jsOptions(Testable $lw, string $method, array $args = []): array
    {
        $rows = $lw->instance()->callSchemaComponentMethod(self::FIELD, $method, $args);

        return collect($rows)->mapWithKeys(fn (array $r) => [$r['value'] => $r['label']])->all();
    }

    public function test_dropdown_starts_with_one_bounded_page_and_never_preloads_the_taxonomy(): void
    {
        $lw = Livewire::test(CreatePost::class)->fillForm(['locale' => 'vi']);

        $first = $this->jsOptions($lw, 'getOptionsForJs');
        $this->assertCount(TaxonomyDirectory::DEFAULT_LIMIT, $first);
        $this->assertArrayNotHasKey($this->last()->id, $first);
        $lw->assertDontSeeHtml('Chuyên mục 25');
    }

    public function test_search_reaches_terms_beyond_the_first_page(): void
    {
        $lw = Livewire::test(CreatePost::class)->fillForm(['locale' => 'vi']);

        $this->assertSame([$this->last()->id => 'Chuyên mục 25'], $this->jsOptions($lw, 'getSearchResultsForJs', ['search' => 'muc 25']));
        $this->assertSame([], $this->jsOptions($lw, 'getSearchResultsForJs', ['search' => '%']));
    }

    public function test_selection_beyond_first_page_saves_reopens_and_keeps_its_label(): void
    {
        $target = $this->last();

        Livewire::test(CreatePost::class)
            ->fillForm(['locale' => 'vi', 'title' => 'P16 bài', 'content' => '<p>x</p>', 'status' => 'draft', 'category_ids' => [(string) $target->id]])
            ->call('create')
            ->assertHasNoErrors();

        $post = Content::query()->where('type', 'post')->latest('id')->firstOrFail();
        $this->assertSame([$target->id], $post->terms()->pluck('cms_terms.id')->map(fn ($v) => (int) $v)->all());

        $lw = Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'vi'])
            ->assertSet('data.category_ids', [$target->id]);
        $this->assertSame([$target->id => 'Chuyên mục 25'], $this->jsOptions($lw, 'getOptionLabelsForJs'));

        // Save again without touching categories, then reopen: still assigned.
        $lw->fillForm(['title' => 'P16 bài 2'])->call('save')->assertHasNoErrors();
        $this->assertSame([$target->id], $post->refresh()->terms()->pluck('cms_terms.id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_submitted_ids_are_validated_server_side(): void
    {
        $tag = app('cms.taxonomy')->createTerm('tag', ['locale' => 'vi', 'name' => 'Thẻ']);
        $enOnly = app('cms.taxonomy')->createTerm('category', ['locale' => 'en', 'name' => 'English only']);
        $gone = $this->categories[0];
        $gone->delete();

        foreach (['tag' => $tag->id, 'unknown' => 999999, 'deleted' => $gone->id, 'untranslated' => $enOnly->id, 'garbage' => 'abc'] as $case => $bad) {
            Livewire::test(CreatePost::class)
                ->fillForm(['locale' => 'vi', 'title' => "Bad $case", 'content' => '<p>x</p>', 'status' => 'draft', 'category_ids' => [(string) $this->categories[1]->id, (string) $bad]])
                ->call('create')
                ->assertHasFormErrors(['category_ids.0']); // Filament validates each element (in-rule on category_ids.*)
        }

        $this->assertSame(0, Content::query()->where('type', 'post')->count());
    }

    public function test_oversize_selection_is_rejected_not_executed_as_an_unbounded_lookup(): void
    {
        $ids = array_map('strval', range(1, TaxonomyDirectory::MAX_LOOKUP_IDS + 1));

        Livewire::test(CreatePost::class)
            ->fillForm(['locale' => 'vi', 'title' => 'Big', 'content' => '<p>x</p>', 'status' => 'draft', 'category_ids' => $ids])
            ->call('create')
            ->assertHasFormErrors(['category_ids.0']); // Filament validates each element (in-rule on category_ids.*)
    }

    public function test_options_follow_the_editing_locale(): void
    {
        $both = $this->categories[2];
        app('cms.taxonomy')->updateTerm($both, ['locale' => 'en', 'name' => 'Category EN']);

        $lw = Livewire::test(CreatePost::class)->fillForm(['locale' => 'en']);
        $this->assertSame([$both->id => 'Category EN'], $this->jsOptions($lw, 'getOptionsForJs'), 'strict: only categories named in en');
    }

    public function test_foreign_locale_assignment_is_preserved_on_save(): void
    {
        $enOnly = app('cms.taxonomy')->createTerm('category', ['locale' => 'en', 'name' => 'English only']);
        $viCat = $this->categories[3];

        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'draft', 'locale' => 'vi', 'title' => 'Giữ', 'slug' => 'giu',
            'content' => '<p>x</p>', 'term_ids' => [$viCat->id, $enOnly->id],
        ], ContentFixtures::context());

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey(), 'translationLocale' => 'vi'])
            ->assertSet('data.category_ids', [$viCat->id])
            ->fillForm(['category_ids' => []])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame([$enOnly->id], $post->refresh()->terms()->pluck('cms_terms.id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_default_category_setting_preselects_only_when_named_in_the_locale(): void
    {
        $target = $this->last();
        app('cms.settings')->set('writing.default_category_id', $target->id, 'integer');

        Livewire::test(CreatePost::class)->assertSet('data.category_ids', [$target->id]);

        app('cms.settings')->set('writing.default_category_id', 999999, 'integer');
        Livewire::test(CreatePost::class)->assertSet('data.category_ids', []);
    }

    public function test_labels_are_escaped_in_the_rendered_picker(): void
    {
        $evil = app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => '<img src=x onerror=alert(1)>', 'sort_order' => -1]);

        $lw = Livewire::test(CreatePost::class)->fillForm(['locale' => 'vi']);
        $lw->assertDontSeeHtml('<img src=x onerror=alert(1)>');
        $this->assertSame((string) $evil->refresh()->localeName('vi'), $this->jsOptions($lw, 'getOptionsForJs')[$evil->id]);
    }

    public function test_picker_maps_are_bounded_and_constant_in_queries(): void
    {
        $count = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        TermPickerOptions::firstPage('category', 'vi', true);

        $ids = array_map(fn (Term $t) => $t->id, $this->categories);
        $this->assertLessThanOrEqual(6, $count(fn () => TermPickerOptions::labels($ids, 'category', 'vi', true)));
        $this->assertLessThanOrEqual(6, $count(fn () => TermPickerOptions::search('category', 'vi', 'chuyên', true)));
        $this->assertCount(TaxonomyDirectory::DEFAULT_LIMIT, TermPickerOptions::search('category', 'vi', 'chuyên', true));
        $this->assertSame([], TermPickerOptions::search('category', 'xx', '', true), 'invalid locale yields no options (reported), never an unfiltered list');
    }
}
