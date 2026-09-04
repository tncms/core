<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Filament\Admin\Pages\ImportDemoPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\DemoImporter;
use TheNguyen\CMS\Services\ThemeManager;
use TheNguyen\CMS\Support\DemoPackage;

/**
 * CORE-THEME-2 — theme-scoped demo presets: native `pages` import (with
 * page-template identifiers and homepage assignment), preview/dry-run,
 * idempotent re-import, rollback ownership and active-theme scoping.
 */
final class ThemeDemoPagesImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fixtures = ['dm-a', 'dm-b'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTheme('dm-a', 'DMA', [
            ['id' => 'landing', 'label' => 'Landing', 'view' => 'pages/templates/landing'],
        ]);
        File::ensureDirectoryExists(base_path('themes/dm-a/views/pages/templates'));
        File::put(base_path('themes/dm-a/views/pages/templates/landing.blade.php'), 'DMA-LANDING {{ $title }}');

        $this->makeTheme('dm-b', 'DMB', []);

        // Theme A demo preset: two pages (one homepage w/ declared template, one
        // with an UNDECLARED template id) + a menu.
        $demo = base_path('themes/dm-a/demo/starter');
        File::ensureDirectoryExists($demo);
        File::put($demo.'/manifest.json', json_encode([
            'type' => 'theme', 'owner' => 'dm-a', 'slug' => 'starter',
            'name' => 'Starter Demo', 'description' => 'Fixture preset', 'version' => '1.0.0',
            'files' => ['pages' => 'pages.json'],
        ]));
        File::put($demo.'/pages.json', json_encode([
            'pages' => [
                [
                    'key' => 'landing',
                    'template' => 'landing',
                    'status' => 'published',
                    'homepage' => true,
                    'translations' => [
                        'vi' => ['title' => 'Trang đích', 'slug' => 'trang-dich', 'content' => '<p>vi body</p>'],
                        'en' => ['title' => 'Landing', 'slug' => 'landing', 'content' => '<p>en body</p>'],
                    ],
                ],
                [
                    'key' => 'about',
                    'template' => 'not-declared',
                    'status' => 'published',
                    'translations' => [
                        'vi' => ['title' => 'Giới thiệu', 'slug' => 'gioi-thieu-demo', 'content' => '<p>about</p>'],
                    ],
                ],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $slug) {
            File::deleteDirectory(base_path('themes/'.$slug));
            File::deleteDirectory(public_path('themes/'.$slug));
        }

        parent::tearDown();
    }

    private function importer(): DemoImporter
    {
        return app('cms.demo_importer');
    }

    private function themes(): ThemeManager
    {
        return app('cms.theme');
    }

    private function package(): DemoPackage
    {
        $package = $this->importer()->find('dm-a', 'starter');
        $this->assertNotNull($package, 'fixture demo package must be discovered');

        return $package;
    }

    // ---- discovery -------------------------------------------------------

    public function test_preset_discovery_is_per_theme_and_theme_without_presets_has_none(): void
    {
        $ids = array_keys($this->importer()->discover());

        $this->assertContains('theme:dm-a:starter', $ids);
        $this->assertSame([], array_filter($ids, fn ($id) => str_starts_with($id, 'theme:dm-b:')));
    }

    // ---- preview (dry-run) ----------------------------------------------

    public function test_preview_reports_plan_without_any_write(): void
    {
        $this->themes()->activate('dm-a');

        $before = Content::query()->count();
        $plan = $this->importer()->preview($this->package());

        $this->assertTrue($plan['ok']);
        $this->assertFalse($plan['reimport']);
        $creates = array_filter($plan['actions'], fn ($a) => $a['file'] === 'pages' && $a['action'] === 'create');
        $this->assertCount(2, $creates);
        $this->assertNotSame([], array_filter($plan['warnings'], fn ($w) => str_contains($w, 'not-declared')));

        $this->assertSame($before, Content::query()->count(), 'preview must not write');
        $this->assertNull(settings()->get('demo.imports.dm-a.starter'), 'preview must not record provenance');
    }

    // ---- import ----------------------------------------------------------

    public function test_import_creates_pages_with_templates_homepage_and_symbolic_keys(): void
    {
        $this->themes()->activate('dm-a');

        $result = $this->importer()->import($this->package());

        $this->assertTrue($result->success);

        $provenance = settings()->get('demo.imports.dm-a.starter');
        $this->assertIsArray($provenance);
        $landingId = $provenance['imported_keys']['page:landing'] ?? null;
        $aboutId = $provenance['imported_keys']['page:about'] ?? null;
        $this->assertIsInt($landingId);
        $this->assertIsInt($aboutId);

        $landing = Content::query()->findOrFail($landingId);
        $this->assertSame('landing', $landing->template, 'declared template id must persist');
        $this->assertSame('page', $landing->type);
        $this->assertCount(2, $landing->translations, 'both locales must import');

        $about = Content::query()->findOrFail($aboutId);
        $this->assertNull($about->template, 'undeclared template must import as no-template');
        $this->assertNotSame([], array_filter($result->warnings, fn ($w) => str_contains($w, 'not-declared')));

        // Homepage assignment.
        $this->assertSame('static_page', settings()->get('reading.homepage_display'));
        $this->assertSame($landingId, (int) settings()->get('reading.homepage_page_id'));

        // The imported homepage renders the declared template over HTTP.
        $response = $this->get('/');
        $response->assertOk();
        $this->assertStringContainsString('DMA-LANDING', $response->getContent());
    }

    public function test_reimport_is_idempotent_and_reuses_the_same_pages(): void
    {
        $this->themes()->activate('dm-a');

        $this->importer()->import($this->package());
        $first = settings()->get('demo.imports.dm-a.starter')['imported_keys'];
        $countAfterFirst = Content::query()->count();

        $result = $this->importer()->import($this->package());
        $this->assertTrue($result->success);

        $second = settings()->get('demo.imports.dm-a.starter')['imported_keys'];
        $this->assertSame($first['page:landing'], $second['page:landing'], 're-import must reuse the same content row');
        $this->assertSame($countAfterFirst, Content::query()->count(), 're-import must not duplicate pages');
    }

    // ---- rollback --------------------------------------------------------

    public function test_reset_removes_only_importer_pages_and_restores_settings(): void
    {
        $this->themes()->activate('dm-a');

        // Owner content that must survive, created BEFORE the import.
        $own = app(ContentManager::class)->create([
            'type' => 'page', 'status' => 'published', 'title' => 'My own page',
            'slug' => 'my-own-page', 'locale' => 'vi', 'content' => '<p>mine</p>',
        ]);

        settings()->set('reading.homepage_display', 'latest_posts');

        $this->importer()->import($this->package());
        $provenance = settings()->get('demo.imports.dm-a.starter');
        $imported = array_values($provenance['imported_keys']);

        $result = $this->importer()->reset($this->package());
        $this->assertTrue($result->success);

        foreach ($imported as $id) {
            $this->assertNull(Content::query()->find($id), 'importer-created page must be removed on reset');
        }

        $this->assertNotNull(Content::query()->find($own->id), 'owner content must survive reset');
        $this->assertSame('latest_posts', settings()->get('reading.homepage_display'), 'pre-import homepage settings must be restored');
        $this->assertNull(settings()->get('demo.imports.dm-a.starter'), 'provenance must be dropped');
    }

    public function test_reimport_after_reset_recreates_pages_cleanly(): void
    {
        // Regression (runtime-cert defect): Content soft-deletes, so a reset
        // that left soft-deleted rows kept the unique (locale, slug) translation
        // index occupied and the next import failed. Reset must purge
        // importer-owned pages for real.
        $this->themes()->activate('dm-a');

        $this->importer()->import($this->package());
        $this->assertTrue($this->importer()->reset($this->package())->success);

        $result = $this->importer()->import($this->package());

        $this->assertTrue($result->success);
        $this->assertSame([], array_values(array_filter($result->warnings, fn ($w) => str_contains($w, 'could not be imported'))), 're-import after reset must not fail page creation');

        $provenance = settings()->get('demo.imports.dm-a.starter');
        $this->assertIsInt($provenance['imported_keys']['page:landing'] ?? null, 'pages must be recreated after a reset');
    }

    // ---- active-theme scoping -------------------------------------------

    public function test_admin_surface_lists_only_active_theme_presets(): void
    {
        $this->themes()->activate('dm-b');

        $page = new ImportDemoPage;
        $ids = array_column($page->packages(), 'id');

        $this->assertNotContains('theme:dm-a:starter', $ids, 'inactive-theme preset must not be importable');

        $this->themes()->activate('dm-a');
        $ids = array_column((new ImportDemoPage)->packages(), 'id');
        $this->assertContains('theme:dm-a:starter', $ids);
    }

    public function test_imported_preset_stays_listed_after_theme_switch_for_reset(): void
    {
        $this->themes()->activate('dm-a');
        $this->importer()->import($this->package());

        $this->themes()->activate('dm-b');
        $rows = (new ImportDemoPage)->packages();
        $row = collect($rows)->firstWhere('id', 'theme:dm-a:starter');

        $this->assertNotNull($row, 'imported preset must stay visible for reset after a theme switch');
        $this->assertTrue($row['inactive_theme']);
    }

    // ---- fixtures --------------------------------------------------------

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
