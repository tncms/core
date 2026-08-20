<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\CurrentResourceContext;
use TheNguyen\CMS\Localization\CurrentResourceReference;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;
use Tests\TestCase;

/**
 * CORE-L10N.1B (Phase P3.3B) — Core Localization freeze guards.
 *
 * These tests FAIL if the compatibility bridge is reintroduced: they prove SEO
 * never writes the current-resource authority, the render boundary is the sole
 * writer, the write is a lock (conflicting/nested writes rejected), the factory
 * stays the only builder of the current-request LocalizationContext, and a
 * brand-new plugin resource integrates with zero Core modification.
 */
final class CoreLocalizationFreezeTest extends TestCase
{
    use RefreshDatabase;

    private string $core;

    protected function setUp(): void
    {
        parent::setUp();

        $this->core = base_path('packages/thenguyen/cms-core/src');

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    /** Source with comments/whitespace stripped, so only real code is inspected. */
    private function code(string $rel): string
    {
        return php_strip_whitespace($this->core.'/'.$rel);
    }

    // ── Objective 1: SEO is not a writer ───────────────────────────────────────

    public function test_seo_manager_never_writes_the_current_resource_context(): void
    {
        $seo = $this->code('Services/SeoManager.php');

        foreach (['CurrentResourceContext', 'CurrentResourceReference', 'CurrentResourcePublisher', 'publishResource', 'current_resource'] as $token) {
            $this->assertStringNotContainsString($token, $seo, "SeoManager must not reference the writer API ({$token}).");
        }
    }

    public function test_a_seo_call_does_not_populate_the_authority(): void
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        seo()->forContent($page->fresh(['translations']), 'en');

        $this->assertFalse(app('cms.localization.current_resource')->has());
    }

    // ── Objective 1/6: the render boundary is the writer ───────────────────────

    public function test_render_boundary_publishes_the_current_resource(): void
    {
        $fc = $this->code('Http/Controllers/FrontendController.php');

        $this->assertStringContainsString('CurrentResourcePublisher', $fc);
        foreach (['publishHome', 'publishContent', 'publishTerm'] as $method) {
            $this->assertStringContainsString($method, $fc, "The render boundary must call {$method}().");
        }
    }

    public function test_no_core_file_reintroduces_a_last_writer_wins_setter(): void
    {
        foreach ($this->corePhpFiles() as $file) {
            $source = php_strip_whitespace($file);
            $this->assertStringNotContainsString("current_resource')->set(", $source, $file);
            $this->assertStringNotContainsString('->set(CurrentResourceReference', $source, $file);
        }
    }

    // ── Objective 2/7: the write is a lock — nested overwrite is impossible ─────

    public function test_conflicting_write_is_rejected_and_the_first_stays_locked(): void
    {
        $context = new CurrentResourceContext;

        $first = CurrentResourceReference::of('page', new \stdClass, 1, 'core');
        $context->publish($first);

        // A widget / Blade component / nested render / stray plugin publishing a
        // different resource cannot redefine the current resource.
        $context->publish(CurrentResourceReference::of('acme.widget', new \stdClass, 9, 'acme'));

        $this->assertSame($first, $context->current());
        $this->assertTrue($context->hadConflict());
    }

    public function test_identical_write_is_idempotent_without_conflict(): void
    {
        $context = new CurrentResourceContext;
        $context->publish(CurrentResourceReference::of('post', new \stdClass, 3, 'core'));
        $context->publish(CurrentResourceReference::of('post', new \stdClass, 3, 'core'));

        $this->assertFalse($context->hadConflict());
        $this->assertSame('post', $context->current()?->type);
    }

    // ── Objective 3/4/8: exactly one builder of the current-request context ─────

    public function test_localization_context_builders_are_the_classified_set_only(): void
    {
        $builders = [];

        foreach ($this->corePhpFiles() as $file) {
            if (str_contains((string) file_get_contents($file), 'new LocalizationContext(')) {
                $builders[] = basename($file);
            }
        }

        sort($builders);

        // The ONLY three constructors, each an isolated concern:
        //  - CurrentLocalizationContextFactory  → the current-request authority
        //  - LocalizedContentUrlService         → a URL builder for a GIVEN resource
        //  - DiagnoseLocalizationCommand        → diagnostics/testing tooling
        $this->assertSame(
            [
                'CurrentLocalizationContextFactory.php',
                'DiagnoseLocalizationCommand.php',
                'LocalizedContentUrlService.php',
            ],
            $builders,
            'A new LocalizationContext builder appeared — route it through the factory or classify it.',
        );
    }

    public function test_only_the_factory_reads_the_current_resource_authority(): void
    {
        // The URL builder and diagnostics build contexts for a supplied resource;
        // they must never read the current-request authority (no competing builder).
        foreach (['Services/LocalizedContentUrlService.php', 'Console/Commands/DiagnoseLocalizationCommand.php'] as $rel) {
            $source = $this->code($rel);
            $this->assertStringNotContainsString('current_resource', $source, "{$rel} must not read the current-resource authority.");
            $this->assertStringNotContainsString('context_factory', $source, "{$rel} must not read the current-resource authority.");
        }
    }

    // ── Objective 9: a brand-new plugin integrates with no Core modification ────

    public function test_a_new_plugin_resource_integrates_without_touching_core(): void
    {
        // 1. The plugin registers a resolver on the shared registry (no Core edit).
        app('cms.localization.resolvers')->register($this->fakeForumResolver());

        // 2. The plugin publishes its resource at ITS render boundary, directly to
        //    the ONE authority — no SeoManager, no Core change.
        app('cms.localization.current_resource')->publish(
            CurrentResourceReference::of('forum.thread', new \stdClass, 42, 'forum'),
        );

        // 3. The switcher resolves the plugin resource through the frozen pipeline.
        $entries = [];
        foreach (language_switcher() as $entry) {
            $entries[$entry['code']] = $entry['url'];
        }

        $this->assertSame('/forum/hello-world', $entries['en']);
        $this->assertSame('/vi/forum/hello-world', $entries['vi']);
    }

    private function fakeForumResolver(): LocalizedResourceResolverContract
    {
        return new class implements LocalizedResourceResolverContract
        {
            public function key(): string
            {
                return 'forum.thread';
            }

            public function priority(): int
            {
                return 50;
            }

            public function supports(LocalizationContext $context): bool
            {
                return $context->type === 'forum.thread';
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                return new RouteDescriptor(
                    resolverKey: 'forum.thread',
                    canonicalType: 'forum.thread',
                    canonicalId: 42,
                    canonicalPath: '/forum/hello-world',
                );
            }
        };
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
