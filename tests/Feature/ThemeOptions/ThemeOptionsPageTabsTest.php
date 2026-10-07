<?php

declare(strict_types=1);

namespace Tests\Feature\ThemeOptions;

use App\Filament\Admin\Pages\ThemeOptionsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;
use TheNguyen\CMS\Services\ThemeCustomCssManager;

/**
 * CORE-THEME-OPTIONS-UX-1 — the admin Theme Options page renders the schema as
 * canonical tabs (like CMS Settings), always exposes the Custom CSS & Code tab,
 * and still persists option values under their existing setting keys. Uses
 * disposable fixture themes so no tracked theme asset is published/mutated.
 */
final class ThemeOptionsPageTabsTest extends TestCase
{
    use RefreshDatabase;

    private const GRID = 'touxp_grid';
    private const BARE = 'touxp_bare';

    /** @var list<string> */
    private array $fixtures = [self::GRID, self::BARE];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('secret-password'),
        ]);
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/' . $slug));
            File::deleteDirectory(public_path('themes/' . $slug));
        }

        parent::tearDown();
    }

    private function activateGrid(): void
    {
        $this->gridTheme();
        app('cms.theme')->activate(self::GRID);
        $this->assertSame(self::GRID, app('cms.theme')->active()?->slug);
    }

    public function test_grouped_schema_renders_canonical_tabs(): void
    {
        $this->activateGrid();

        Livewire::test(ThemeOptionsPage::class)
            ->assertOk()
            ->assertSee(tn_trans('Brand'))
            ->assertSee(tn_trans('Colors'))
            ->assertSee(tn_trans('Layout'))
            ->assertSee(tn_trans('SEO & Social'))
            ->assertSee(tn_trans('Custom CSS & Code'));
    }

    public function test_save_persists_option_values_under_existing_keys(): void
    {
        $this->activateGrid();

        Livewire::test(ThemeOptionsPage::class)
            ->set('data.primary_color', '#123456')
            ->set('data.footer_text', 'Edited footer')
            // Numeric-keyed select ({"280":"280px"}): the real value must save,
            // not the label — proving the option-map coercion fix.
            ->set('data.sidebar_width', '280')
            ->call('save')
            ->assertHasNoErrors();

        $options = app('cms.theme_option');
        $this->assertSame('#123456', $options->get('primary_color', null, self::GRID));
        $this->assertSame('Edited footer', $options->get('footer_text', null, self::GRID));
        // Real value persisted (280), not the label "280px"; numeric cast by the
        // settings layer is expected, so compare loosely.
        $this->assertEquals('280', $options->get('sidebar_width', null, self::GRID));
        $this->assertNotSame('280px', (string) $options->get('sidebar_width', null, self::GRID));
        // Unchanged storage contract: theme_options.{slug}.{key}.
        $this->assertTrue(settings()->has('theme_options.' . self::GRID . '.primary_color'));
    }

    public function test_custom_css_saves_through_the_code_tab(): void
    {
        $this->activateGrid();

        Livewire::test(ThemeOptionsPage::class)
            ->set('data.' . ThemeCustomCssManager::FRONTEND_KEY, '.cert{color:red}')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringContainsString(
            '.cert',
            app('cms.theme_custom_css')->frontendCss(self::GRID),
        );
    }

    public function test_schemaless_theme_still_shows_only_the_custom_css_tab(): void
    {
        $this->bareTheme();
        app('cms.theme')->activate(self::BARE);
        $this->assertSame(self::BARE, app('cms.theme')->active()?->slug);

        Livewire::test(ThemeOptionsPage::class)
            ->assertOk()
            ->assertSee(tn_trans('Custom CSS & Code'))
            ->assertDontSee(tn_trans('Brand'));
    }

    // --- fixtures ------------------------------------------------------------

    /** A valid standalone theme with a grouped option schema. */
    private function gridTheme(): void
    {
        $dir = $this->standalone(self::GRID, 'Grid Theme');

        File::put($dir . '/theme.options.json', json_encode(['options' => ['sections' => [
            ['key' => 'brand', 'group' => 'brand', 'label' => 'Brand', 'fields' => [
                ['key' => 'logo', 'label' => 'Logo', 'type' => 'image'],
            ]],
            ['key' => 'colors', 'group' => 'colors', 'label' => 'Colors', 'fields' => [
                ['key' => 'primary_color', 'label' => 'Primary', 'type' => 'color', 'default' => '#2563eb'],
            ]],
            ['key' => 'layout', 'group' => 'layout', 'label' => 'Layout', 'fields' => [
                ['key' => 'footer_text', 'label' => 'Footer text', 'type' => 'textarea', 'default' => 'Footer'],
                ['key' => 'sidebar_width', 'label' => 'Sidebar width', 'type' => 'select', 'default' => '320',
                    'options' => ['280' => '280px', '320' => '320px', '360' => '360px']],
            ]],
            ['key' => 'social', 'group' => 'seo', 'label' => 'Social', 'fields' => [
                ['key' => 'twitter', 'label' => 'Twitter', 'type' => 'text'],
            ]],
        ]]]));
    }

    /** A valid standalone theme with NO option schema. */
    private function bareTheme(): void
    {
        $this->standalone(self::BARE, 'Bare Theme');
    }

    private function standalone(string $slug, string $name): string
    {
        $dir = base_path('themes/' . $slug);

        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir . '/views/' . $d);
        }
        File::ensureDirectoryExists($dir . '/assets/css');
        File::put($dir . '/views/layouts/master.blade.php', 'MASTER');
        File::put($dir . '/views/pages/page.blade.php', 'PAGE');
        File::put($dir . '/views/posts/post.blade.php', 'POST');
        File::put($dir . '/views/archives/index.blade.php', 'ARCHIVE');
        File::put($dir . '/assets/css/main.css', '/*' . $slug . '*/');
        File::put($dir . '/theme.json', json_encode([
            'name' => $name,
            'slug' => $slug,
            'version' => '1.0.0',
            'author' => 't',
            'assets' => [['handle' => $slug . '-css', 'src' => 'css/main.css', 'primary' => true]],
        ]));

        return $dir;
    }
}
