<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Services\PageTemplateRegistry;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * CORE-THEME-2 — active-theme page-template registry: manifest validation,
 * parent/child inheritance and the activation fail-closed gate.
 */
final class PageTemplateRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = [
        'pt-a', 'pt-b', 'pt-parent', 'pt-child', 'pt-bad-id', 'pt-bad-view',
        'pt-traversal', 'pt-namespace', 'pt-dupe', 'pt-missing-view',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTheme('pt-a', 'A', [
            ['id' => 'cv', 'label' => 'CV', 'view' => 'pages/templates/cv'],
            ['id' => 'contact', 'label' => 'Contact', 'view' => 'pages.templates.contact', 'description' => 'Contact layout'],
        ], ['pages/templates/cv', 'pages/templates/contact']);

        $this->makeTheme('pt-b', 'B', [], []);

        $this->makeTheme('pt-parent', 'P', [
            ['id' => 'portfolio', 'label' => 'Portfolio', 'view' => 'pages/templates/portfolio'],
            ['id' => 'cv', 'label' => 'Parent CV', 'view' => 'pages/templates/cv'],
        ], ['pages/templates/portfolio', 'pages/templates/cv']);

        // Child overrides "cv", inherits "portfolio".
        $this->makeChild('pt-child', 'pt-parent', [
            ['id' => 'cv', 'label' => 'Child CV', 'view' => 'pages/templates/child-cv'],
        ], ['pages/templates/child-cv']);

        $this->makeTheme('pt-bad-id', 'X', [
            ['id' => 'Bad ID!', 'label' => 'Bad', 'view' => 'pages/templates/x'],
        ], ['pages/templates/x']);

        $this->makeTheme('pt-bad-view', 'X', [
            ['id' => 'x', 'label' => 'X', 'view' => '/etc/passwd'],
        ], []);

        $this->makeTheme('pt-traversal', 'X', [
            ['id' => 'x', 'label' => 'X', 'view' => '../../default/views/pages/page'],
        ], []);

        $this->makeTheme('pt-namespace', 'X', [
            ['id' => 'x', 'label' => 'X', 'view' => 'other::pages.page'],
        ], []);

        $this->makeTheme('pt-dupe', 'X', [
            ['id' => 'x', 'label' => 'One', 'view' => 'pages/templates/x'],
            ['id' => 'x', 'label' => 'Two', 'view' => 'pages/templates/x'],
        ], ['pages/templates/x']);

        $this->makeTheme('pt-missing-view', 'X', [
            ['id' => 'x', 'label' => 'X', 'view' => 'pages/templates/not-there'],
        ], []);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    private function registry(): PageTemplateRegistry
    {
        return app('cms.page_templates');
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    // ---- manifest validation -------------------------------------------

    public function test_valid_declarations_are_parsed_with_normalized_views(): void
    {
        $templates = $this->registry()->templatesFor('pt-a');

        $this->assertSame(['cv', 'contact'], array_keys($templates));
        $this->assertSame('CV', $templates['cv']->label);
        $this->assertSame('pages/templates/cv', $templates['cv']->view);
        $this->assertSame('theme::pages.templates.cv', $templates['cv']->themeView());
        // Dot-separated declaration normalizes identically.
        $this->assertSame('theme::pages.templates.contact', $templates['contact']->themeView());
        $this->assertSame('Contact layout', $templates['contact']->description);
        $this->assertSame([], $this->registry()->errorsFor('pt-a'));
    }

    public function test_theme_without_declarations_is_valid_and_empty(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-b'));
        $this->assertSame([], $this->registry()->errorsFor('pt-b'));
    }

    public function test_invalid_id_is_rejected(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-bad-id'));
        $this->assertNotSame([], $this->registry()->errorsFor('pt-bad-id'));
    }

    public function test_absolute_path_view_is_rejected(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-bad-view'));
        $this->assertNotSame([], $this->registry()->errorsFor('pt-bad-view'));
    }

    public function test_traversal_view_is_rejected(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-traversal'));
        $this->assertNotSame([], $this->registry()->errorsFor('pt-traversal'));
    }

    public function test_namespace_injection_is_rejected(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-namespace'));
        $this->assertNotSame([], $this->registry()->errorsFor('pt-namespace'));
    }

    public function test_duplicate_id_is_rejected(): void
    {
        $this->assertNotSame([], $this->registry()->errorsFor('pt-dupe'));
    }

    public function test_undeclared_missing_view_is_rejected(): void
    {
        $this->assertSame([], $this->registry()->templatesFor('pt-missing-view'));
        $this->assertNotSame([], $this->registry()->errorsFor('pt-missing-view'));
    }

    // ---- parent/child ---------------------------------------------------

    public function test_child_inherits_parent_templates_and_overrides_collisions(): void
    {
        $templates = $this->registry()->templatesFor('pt-child');

        $this->assertArrayHasKey('portfolio', $templates);
        $this->assertSame('pt-parent', $templates['portfolio']->owner);

        $this->assertArrayHasKey('cv', $templates);
        $this->assertSame('pt-child', $templates['cv']->owner, 'child must override the parent declaration');
        $this->assertSame('Child CV', $templates['cv']->label);
        $this->assertSame([], $this->registry()->errorsFor('pt-child'));
    }

    // ---- activation gate ------------------------------------------------

    public function test_activation_fails_closed_on_invalid_page_templates(): void
    {
        $this->assertTrue($this->themes()->activate('pt-a'));

        foreach (['pt-bad-id', 'pt-bad-view', 'pt-traversal', 'pt-namespace', 'pt-dupe', 'pt-missing-view'] as $slug) {
            $this->assertFalse($this->themes()->activate($slug), "activating {$slug} must fail closed");
            $this->assertSame('pt-a', $this->themes()->activeSlug(), "previous theme must be preserved after {$slug}");
        }
    }

    public function test_activation_succeeds_with_valid_or_absent_declarations(): void
    {
        $this->assertTrue($this->themes()->activate('pt-b'));
        $this->assertTrue($this->themes()->activate('pt-child'));
    }

    // ---- resolution allowlist ------------------------------------------

    public function test_view_for_id_resolves_only_declared_templates(): void
    {
        $this->themes()->activate('pt-a');

        $this->assertSame('theme::pages.templates.cv', $this->registry()->viewForId('cv'));
        $this->assertNull($this->registry()->viewForId(''));
        $this->assertNull($this->registry()->viewForId(null));
        $this->assertNull($this->registry()->viewForId('undeclared'));
        $this->assertNull($this->registry()->viewForId('../../etc/passwd'));
        $this->assertNull($this->registry()->viewForId('other::pages.page'));
        $this->assertNull($this->registry()->viewForId("cv\0"));
    }

    // ---- fixtures --------------------------------------------------------

    /**
     * @param  list<array<string, string>>  $declarations
     * @param  list<string>  $views  template views to create on disk
     */
    private function makeTheme(string $slug, string $marker, array $declarations, array $views): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }

        File::put($dir.'/views/layouts/master.blade.php', $marker.'-MASTER @yield(\'content\')');
        File::put($dir.'/views/pages/page.blade.php', $marker.'-PAGE');
        File::put($dir.'/views/posts/post.blade.php', $marker.'-POST');
        File::put($dir.'/views/archives/index.blade.php', $marker.'-ARCHIVE');

        foreach ($views as $view) {
            $path = $dir.'/views/'.$view.'.blade.php';
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $marker.'-TPL-'.basename($view));
        }

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'page_templates' => $declarations,
        ]));
    }

    /**
     * @param  list<array<string, string>>  $declarations
     * @param  list<string>  $views
     */
    private function makeChild(string $slug, string $parent, array $declarations, array $views): void
    {
        $dir = base_path('themes/'.$slug);
        File::ensureDirectoryExists($dir.'/views/pages');

        File::put($dir.'/views/pages/page.blade.php', 'CHILD-PAGE');

        foreach ($views as $view) {
            $path = $dir.'/views/'.$view.'.blade.php';
            File::ensureDirectoryExists(dirname($path));
            File::put($path, 'CHILD-TPL-'.basename($view));
        }

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'parent' => $parent,
            'page_templates' => $declarations,
        ]));
    }
}
