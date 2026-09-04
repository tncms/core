<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\PageResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * CORE-THEME-2 — Page editor Template selector: options come only from the
 * active theme's validated declarations; a stored legacy identifier is shown
 * as unavailable instead of being destroyed; no filesystem paths leak.
 */
final class PageTemplateSelectorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = ['sel-a', 'sel-b'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTheme('sel-a', [
            ['id' => 'cv', 'label' => 'CV', 'view' => 'pages/templates/cv'],
        ]);
        File::ensureDirectoryExists(base_path('themes/sel-a/views/pages/templates'));
        File::put(base_path('themes/sel-a/views/pages/templates/cv.blade.php'), 'CV');

        $this->makeTheme('sel-b', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function templateOptions(?Content $record): array
    {
        $method = new ReflectionMethod(PageResource::class, 'pageTemplateOptions');

        return $method->invoke(null, $record);
    }

    public function test_options_list_only_active_theme_declarations(): void
    {
        app(ThemeManager::class)->activate('sel-a');

        $options = $this->templateOptions(null);

        $this->assertSame(['cv'], array_keys($options));
        $this->assertSame('CV', $options['cv']);
        // Never a filesystem path or Blade filename.
        $this->assertStringNotContainsString('/', implode(' ', $options));
        $this->assertStringNotContainsString('.blade', implode(' ', array_keys($options)));
    }

    public function test_theme_without_declarations_yields_no_options(): void
    {
        app(ThemeManager::class)->activate('sel-b');

        $this->assertSame([], $this->templateOptions(null));
    }

    public function test_stored_legacy_value_is_listed_as_unavailable(): void
    {
        app(ThemeManager::class)->activate('sel-a');
        $page = Content::create(['type' => 'page', 'status' => 'draft', 'template' => 'from-other-theme']);

        $options = $this->templateOptions($page);

        $this->assertArrayHasKey('cv', $options);
        $this->assertArrayHasKey('from-other-theme', $options);
        $this->assertStringContainsString('from-other-theme', $options['from-other-theme']);
        $this->assertStringContainsString(tn_trans('Unavailable in the active theme'), $options['from-other-theme']);
    }

    /**
     * @param  list<array<string, string>>  $declarations
     */
    private function makeTheme(string $slug, array $declarations): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }

        File::put($dir.'/views/layouts/master.blade.php', 'M');
        File::put($dir.'/views/pages/page.blade.php', 'P');
        File::put($dir.'/views/posts/post.blade.php', 'P');
        File::put($dir.'/views/archives/index.blade.php', 'A');

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'page_templates' => $declarations,
        ]));
    }
}
