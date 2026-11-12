<?php

declare(strict_types=1);

namespace Tests\Feature\Locale;

use App\Filament\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Filament\Admin\Resources\MenuResource\Pages\EditMenu;
use App\Filament\Admin\Resources\PageResource\Pages\EditPage;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Filament\Admin\Resources\TagResource\Pages\EditTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Services\LocalePreferenceManager;

/**
 * CORE-P12-4 — an editor instance keeps the content locale it was opened in.
 *
 * The content editing locale is a per-user/session preference (?locale= →
 * users.editing_locale → session). Opening another tab on ?locale=en updates
 * that preference. An edit form that was opened on the Vietnamese translation
 * must still save into the Vietnamese translation: the save request (a Livewire
 * update, which carries no ?locale) must not re-resolve the locale from the
 * preference that another tab just changed.
 */
final class EditorInstanceLocaleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CmsLanguageSeeder::class);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.settings')->set('theme.active', 'default');
        $this->admin = User::factory()->create();
        config(['cms.super_admin_emails' => [$this->admin->email]]);
        $this->actingAs($this->admin);
    }

    /** What opening a page with ?locale=$code in another tab does to the shared preference. */
    private function otherTabOpens(string $code): void
    {
        $this->admin->forceFill(['editing_locale' => $code])->saveQuietly();
        session()->put(LocalePreferenceManager::EDITING_SESSION_KEY, $code);
    }

    private function bilingualContent(string $type): \TheNguyen\CMS\Models\Content
    {
        $c = app('cms.content')->create(['type' => $type, 'status' => 'draft', 'locale' => 'en', 'title' => 'EN original', 'slug' => "en-$type",
            'content' => '<p>EN body</p>'], \Tests\Support\ContentFixtures::context());

        return app('cms.content')->update($c, ['locale' => 'vi', 'title' => 'VI gốc', 'slug' => "vi-$type", 'content' => '<p>VI body</p>'], null, \Tests\Support\ContentFixtures::context());
    }

    /** @return array<string, array{class-string, string}> */
    public static function contentEditors(): array
    {
        return ['post' => [EditPost::class, 'post'], 'page' => [EditPage::class, 'page']];
    }

    /** @dataProvider contentEditors */
    public function test_a_content_editor_saves_into_the_translation_it_was_opened_on(string $page, string $type): void
    {
        $content = $this->bilingualContent($type);
        $this->otherTabOpens('vi');

        $tabA = Livewire::test($page, ['record' => $content->getRouteKey()])
            ->assertSet('data.title', 'VI gốc');

        $this->otherTabOpens('en');

        $tabA->fillForm(['title' => 'VI sửa từ tab A'])->call('save')->assertHasNoErrors();

        $t = fn (string $l) => $content->translations()->where('locale', $l)->value('title');
        $this->assertSame('VI sửa từ tab A', $t('vi'), 'tab A edit must land in the Vietnamese translation');
        $this->assertSame('EN original', $t('en'), 'the English translation must not be overwritten by tab A');
    }

    /** @return array<string, array{class-string, string}> */
    public static function termEditors(): array
    {
        return ['category' => [EditCategory::class, 'category'], 'tag' => [EditTag::class, 'tag']];
    }

    /** @dataProvider termEditors */
    public function test_a_term_editor_saves_into_the_translation_it_was_opened_on(string $page, string $taxonomy): void
    {
        $taxonomies = app('cms.taxonomy');
        $term = $taxonomies->createTerm($taxonomy, ['locale' => 'en', 'name' => 'EN term', 'slug' => "en-$taxonomy"]);
        $term = $taxonomies->updateTerm($term, ['locale' => 'vi', 'name' => 'VI mục', 'slug' => "vi-$taxonomy"]);
        $this->otherTabOpens('vi');

        $tabA = Livewire::test($page, ['record' => $term->getRouteKey()])->assertSet('data.name', 'VI mục');

        $this->otherTabOpens('en');

        $tabA->fillForm(['name' => 'VI sửa'])->call('save')->assertHasNoErrors();

        $n = fn (string $l) => $term->translations()->where('locale', $l)->value('name');
        $this->assertSame('VI sửa', $n('vi'));
        $this->assertSame('EN term', $n('en'));
    }

    public function test_a_menu_editor_saves_into_the_translation_it_was_opened_on(): void
    {
        $menus = app('cms.menu');
        $menu = $menus->createMenu(['locale' => 'en', 'name' => 'EN menu', 'slug' => 'p12-menu']);
        $menu = $menus->updateMenu($menu, ['locale' => 'vi', 'name' => 'VI menu']);
        $this->otherTabOpens('vi');

        $tabA = Livewire::test(EditMenu::class, ['record' => $menu->getRouteKey()])->assertSet('data.name', 'VI menu');

        $this->otherTabOpens('en');

        $tabA->fillForm(['name' => 'VI menu sửa'])->call('save')->assertHasNoErrors();

        $n = fn (string $l) => $menu->translations()->where('locale', $l)->value('name');
        $this->assertSame('VI menu sửa', $n('vi'));
        $this->assertSame('EN menu', $n('en'));
    }

    /** @return array<string, array{string}> */
    public static function forgedLocales(): array
    {
        return ['another active locale' => ['en'], 'unsupported' => ['xx'], 'empty' => ['']];
    }

    /**
     * The pinned instance locale is component state the browser sends back on
     * every update. A forged value must not redirect the save to another (or a
     * non-existent) translation.
     *
     * @dataProvider forgedLocales
     */
    public function test_the_instance_locale_cannot_be_changed_by_the_client(string $forged): void
    {
        $content = $this->bilingualContent('post');
        $this->otherTabOpens('vi');

        $component = Livewire::test(EditPost::class, ['record' => $content->getRouteKey()])->assertSet('data.title', 'VI gốc');

        try {
            $component->set('translationLocale', $forged)->fillForm(['title' => 'forged'])->call('save');
        } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
            // Refused by the framework: nothing was written.
        }

        $rows = $content->fresh()->translations()->pluck('title', 'locale')->all();
        $this->assertSame(['en' => 'EN original', 'vi' => 'VI gốc'], array_intersect_key($rows, ['en' => 1, 'vi' => 1]) + [], 'no translation was overwritten');
        $this->assertSame(['en', 'vi'], collect(array_keys($rows))->sort()->values()->all(), 'no translation row for a forged locale');
    }

    public function test_an_instance_locale_that_is_no_longer_active_is_refused_on_save(): void
    {
        $content = $this->bilingualContent('post');
        $this->otherTabOpens('en');

        $tabA = Livewire::test(EditPost::class, ['record' => $content->getRouteKey()])->assertSet('data.title', 'EN original');

        app('cms.language')->update(app('cms.language')->find('en'), ['is_active' => false]);
        $this->otherTabOpens('vi');

        $tabA->fillForm(['title' => 'should not land anywhere'])->call('save');

        $t = fn (string $l) => $content->fresh()->translations()->where('locale', $l)->value('title');
        $this->assertSame('EN original', $t('en'));
        $this->assertSame('VI gốc', $t('vi'));
    }
}
