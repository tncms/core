<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Http\Controllers\FrontendController;
use TheNguyen\CMS\Localization\CurrentResourceContext;
use TheNguyen\CMS\Localization\CurrentResourcePublisher;
use TheNguyen\CMS\Models\Content;
use Tests\TestCase;

/**
 * CORE-FREEZE-4 (WORK B/C/G/H) — the current-resource render-boundary runtime.
 *
 * Locks the ONE authoritative frontend flow:
 *
 *     locale → resolve → publish current resource → SEO → render
 *
 * These tests prove the render boundary publishes the correct resource BEFORE
 * SEO is attached (the CORE-FREEZE-4 reorder), that a preview renders under its
 * own locale, and — resolving Conflict 2 — that LanguageManager stays the single
 * locale-STATE authority: only the classified entry points ({@see FrontendController}
 * and the {@see \TheNguyen\CMS\Http\Middleware\ApplyPublicLocale} middleware, plus
 * the {@see \TheNguyen\CMS\Localization\LocaleTransition} switch runtime) write it,
 * so there is no duplicate locale runtime even though there are two entry points.
 */
final class CurrentResourceRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private string $core;

    protected function setUp(): void
    {
        parent::setUp();

        $this->core = base_path('packages/thenguyen/cms-core/src');

        // English default (unprefixed), Vietnamese secondary (prefixed) — matching
        // the CORE-FREEZE-4 examples (EN /home, VI /vi/trang-chu).
        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);

        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
    }

    // ── WORK B: the render boundary publishes each core resource ────────────────

    public function test_render_boundary_publishes_each_core_resource_reference(): void
    {
        $page = $this->bilingualPage();
        $post = $this->bilingualPost();
        $category = $this->bilingualCategory();
        $tag = $this->bilingualTag();

        $this->assertPublished(fn (CurrentResourcePublisher $p) => $p->publishHome(), 'home', null);
        $this->assertPublished(fn (CurrentResourcePublisher $p) => $p->publishContent($page), 'page', $page->getKey());
        $this->assertPublished(fn (CurrentResourcePublisher $p) => $p->publishContent($post), 'post', $post->getKey());
        $this->assertPublished(fn (CurrentResourcePublisher $p) => $p->publishTerm($category, 'category'), 'category', $category->getKey());
        $this->assertPublished(fn (CurrentResourcePublisher $p) => $p->publishTerm($tag, 'tag'), 'tag', $tag->getKey());
    }

    /** Publish through a fresh (write-once) context and assert the resulting reference. */
    private function assertPublished(callable $publish, string $type, int|string|null $identity): void
    {
        $context = new CurrentResourceContext;
        $publish(new CurrentResourcePublisher($context));

        $reference = $context->current();

        $this->assertNotNull($reference, "publishing the {$type} resource must set the authority.");
        $this->assertSame($type, $reference->type);
        $this->assertSame('core', $reference->owner);

        if ($identity !== null) {
            $this->assertSame($identity, $reference->identity);
        }
    }

    // ── WORK B/C: publish precedes SEO in every frontend action (structural) ────

    public function test_publish_precedes_seo_in_every_frontend_action(): void
    {
        $fc = (string) file_get_contents($this->core.'/Http/Controllers/FrontendController.php');

        // No action attaches SEO immediately before publishing the current
        // resource — the reorder guarantees publish → SEO, never SEO → publish.
        $this->assertDoesNotMatchRegularExpression(
            '/->seo->for\w+\([^;]*\);\s*\$this->resources->publish/',
            $fc,
            'SEO must never be attached before the current resource is published (publish → SEO).',
        );

        // home() publishes before attaching SEO (comment lines may sit between).
        $this->assertLessThan(
            (int) strpos($fc, '->seo->forHome('),
            (int) strpos($fc, '->resources->publishHome('),
            'home() must publish the current resource before attaching SEO.',
        );
    }

    // ── WORK G: preview renders under its own locale ────────────────────────────

    public function test_preview_render_publishes_and_uses_its_own_locale(): void
    {
        $page = $this->bilingualPage();
        $controller = app(FrontendController::class);

        foreach (['en' => 'Home', 'vi' => 'Trang chủ'] as $locale => $title) {
            app('cms.localization.current_resource')->clear();

            $controller->renderPreviewable($page->fresh(['translations']), $locale);

            $this->assertSame($locale, app('cms.language')->currentCode(), 'preview must set its own locale.');
            $this->assertSame($title, app('cms.seo')->title(), "preview {$locale} must render its own locale metadata.");
            $this->assertSame('page', app('cms.localization.current_resource')->current()?->type);
        }
    }

    // ── WORK H / Conflict 2: one locale-state authority (LanguageManager) ────────

    public function test_language_manager_is_the_single_locale_state_authority(): void
    {
        $writers = [];

        foreach ($this->corePhpFiles() as $file) {
            if (str_contains((string) file_get_contents($file), '->setCurrent(')) {
                $writers[] = basename($file);
            }
        }

        sort($writers);

        // The ONLY three entry points that change the public locale, each writing
        // the SAME LanguageManager authority — no duplicate locale runtime:
        //  - FrontendController  → the Core frontend render boundary
        //  - ApplyPublicLocale   → the plugin/route locale-apply middleware
        //  - LocaleTransition    → the public locale-switch runtime
        $this->assertSame(
            ['ApplyPublicLocale.php', 'FrontendController.php', 'LocaleTransition.php'],
            $writers,
            'A new locale-state writer appeared — the public locale must be written only through LanguageManager::setCurrent from the classified entry points.',
        );
    }

    // ── fixtures ────────────────────────────────────────────────────────────────

    private function bilingualPage(): Content
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi',
            'title' => 'Trang chủ', 'slug' => 'trang-chu', 'content' => '<p>vi</p>',
        ]);
    }

    private function bilingualPost(): Content
    {
        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'published', 'locale' => 'en',
            'title' => 'Hello', 'slug' => 'hello', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($post, [
            'type' => 'post', 'locale' => 'vi',
            'title' => 'Xin chào', 'slug' => 'xin-chao', 'content' => '<p>vi</p>',
        ]);
    }

    private function bilingualCategory(): object
    {
        $term = app('cms.taxonomy')->createTerm('category', [
            'locale' => 'en', 'name' => 'News', 'slug' => 'news',
        ]);

        return app('cms.taxonomy')->updateTerm($term, [
            'locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc',
        ]);
    }

    private function bilingualTag(): object
    {
        $term = app('cms.taxonomy')->createTerm('tag', [
            'locale' => 'en', 'name' => 'PHP', 'slug' => 'php',
        ]);

        return app('cms.taxonomy')->updateTerm($term, [
            'locale' => 'vi', 'name' => 'PHP', 'slug' => 'php-vi',
        ]);
    }

    /** @return array<int, string> */
    private function corePhpFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->core, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
