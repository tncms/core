<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\ThemeManager;

/**
 * CORE-THEME-2 — frontend resolution of the stored page-template identifier
 * through the active theme's allowlist, over real HTTP requests.
 */
final class PageTemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = ['ptr-a', 'ptr-b'];

    protected function setUp(): void
    {
        parent::setUp();

        // Theme A declares "cv"; theme B declares nothing.
        $this->makeTheme('ptr-a', 'PTRA', [
            ['id' => 'cv', 'label' => 'CV', 'view' => 'pages/templates/cv'],
        ]);
        File::ensureDirectoryExists(base_path('themes/ptr-a/views/pages/templates'));
        File::put(
            base_path('themes/ptr-a/views/pages/templates/cv.blade.php'),
            'PTRA-CV-TEMPLATE {{ $title }}'
        );

        $this->makeTheme('ptr-b', 'PTRB', []);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    private function contents(): ContentManager
    {
        return app(ContentManager::class);
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    private function makePage(string $title, string $slug, ?string $template): Content
    {
        return $this->contents()->create([
            'type' => 'page',
            'status' => 'published',
            'title' => $title,
            'slug' => $slug,
            // Default locale so the unprefixed frontend URL resolves the page.
            'locale' => app('cms.language')->defaultCode(),
            'content' => '<p>Body of '.$title.'</p>',
            'template' => $template,
        ]);
    }

    public function test_default_template_renders_canonical_page_view(): void
    {
        $this->assertTrue($this->themes()->activate('ptr-a'));
        $this->makePage('Plain', 'plain-page', null);

        $response = $this->get('/plain-page');

        $response->assertOk();
        $this->assertStringContainsString('PTRA-PAGE', $response->getContent());
        $this->assertStringNotContainsString('PTRA-CV-TEMPLATE', $response->getContent());
    }

    public function test_declared_template_renders_declared_view(): void
    {
        $this->assertTrue($this->themes()->activate('ptr-a'));
        $this->makePage('CV', 'cv-page', 'cv');

        $response = $this->get('/cv-page');

        $response->assertOk();
        $this->assertStringContainsString('PTRA-CV-TEMPLATE', $response->getContent());
        $this->assertStringContainsString('CV', $response->getContent());
        $this->assertStringNotContainsString('PTRA-PAGE', $response->getContent());
    }

    public function test_injected_invalid_database_value_renders_safely(): void
    {
        $this->assertTrue($this->themes()->activate('ptr-a'));
        $page = $this->makePage('Evil', 'evil-page', null);

        // Bypass the admin allowlist: raw hostile values straight into the DB.
        foreach (['../../../etc/passwd', 'other::pages.page', 'layouts.master', "cv\0", 'undeclared-id'] as $evil) {
            Content::query()->whereKey($page->id)->update(['template' => $evil]);

            $response = $this->get('/evil-page');

            $response->assertOk();
            $this->assertStringContainsString('PTRA-PAGE', $response->getContent(), "value [{$evil}] must fall back to the canonical page view");
            $this->assertStringNotContainsString('PTRA-CV-TEMPLATE', $response->getContent());
        }
    }

    public function test_theme_switch_preserves_value_but_renders_new_theme_default(): void
    {
        $this->assertTrue($this->themes()->activate('ptr-a'));
        $page = $this->makePage('CV', 'switch-page', 'cv');

        $this->assertTrue($this->themes()->activate('ptr-b'));

        // Stored identifier untouched by the switch.
        $this->assertSame('cv', $page->fresh()->template);

        $response = $this->get('/switch-page');

        $response->assertOk();
        // Theme B declares nothing → canonical B page view; no leakage of the
        // now-inactive theme A template view (no per-view cross-theme fallback).
        $this->assertStringContainsString('PTRB-PAGE', $response->getContent());
        $this->assertStringNotContainsString('PTRA-CV-TEMPLATE', $response->getContent());
        $this->assertStringNotContainsString('PTRA-PAGE', $response->getContent());
    }

    /**
     * @param  list<array<string, string>>  $declarations
     */
    private function makeTheme(string $slug, string $marker, array $declarations): void
    {
        $dir = base_path('themes/'.$slug);
        foreach (['layouts', 'pages', 'posts', 'archives'] as $d) {
            File::ensureDirectoryExists($dir.'/views/'.$d);
        }

        File::put($dir.'/views/layouts/master.blade.php', $marker.'-MASTER');
        File::put($dir.'/views/pages/page.blade.php', $marker.'-PAGE {{ $title }}');
        File::put($dir.'/views/posts/post.blade.php', $marker.'-POST');
        File::put($dir.'/views/archives/index.blade.php', $marker.'-ARCHIVE');

        File::put($dir.'/theme.json', json_encode([
            'name' => $slug, 'slug' => $slug, 'version' => '1.0.0', 'author' => 't',
            'page_templates' => $declarations,
        ]));
    }
}
