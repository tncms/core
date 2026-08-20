<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\LocalizedResourceResolverRegistry;
use TheNguyen\CMS\Localization\RouteDescriptor;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\LocalizedContentUrlService;
use Tests\TestCase;

/**
 * CORE-L10N.1B (Phase P3.2) — structural freeze guards.
 *
 * These tests fail if a second localized-URL authority is (re)introduced: they
 * prove the compatibility helpers delegate to the ONE service, that resolvers /
 * descriptors stay facts-only, that Core imports no plugin classes, that the
 * Preview + content-URL services never reach for the legacy helpers, and that a
 * third-party resource plugs in through the registry with no Core conditional.
 */
final class LocalizationAuthorityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $language = app('cms.language');
        $language->create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        $language->create(['code' => 'vi', 'name' => 'Vietnamese', 'native_name' => 'Tiếng Việt', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        app('cms.taxonomy')->ensureCoreTaxonomies();
        app('cms.language')->setCurrent('en');
        app()->setLocale('en');
    }

    private function service(): LocalizedContentUrlService
    {
        return app('cms.localization.content_url');
    }

    private function bilingualPublishedPage(): Content
    {
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'Home', 'slug' => 'home', 'content' => '<p>en</p>',
        ]);

        return app('cms.content')->update($page, [
            'type' => 'page', 'locale' => 'vi', 'title' => 'Trang chủ', 'slug' => 'trang-chu',
        ]);
    }

    private function bilingualCategory(): Term
    {
        $term = app('cms.taxonomy')->createTerm('category', [
            'locale' => 'en', 'name' => 'News', 'slug' => 'news',
        ]);

        return app('cms.taxonomy')->updateTerm($term, [
            'locale' => 'vi', 'name' => 'Tin tức', 'slug' => 'tin-tuc',
        ]);
    }

    // ── compatibility helpers delegate (byte-identical) ────────────────────────

    public function test_content_url_delegates_to_the_one_service_byte_identically(): void
    {
        $page = $this->bilingualPublishedPage();

        // Delegation: the facade returns exactly the service output (or '#').
        $this->assertSame($this->service()->forResource($page, 'vi'), content_url($page, 'vi'));
        $this->assertSame($this->service()->forResource($page, 'en'), content_url($page, 'en'));

        // Byte-identity with the legacy root-relative form.
        $this->assertSame('/vi/trang-chu', content_url($page, 'vi'));
        $this->assertSame('/home', content_url($page, 'en'));
    }

    public function test_content_url_preserves_the_hash_sentinel_for_missing_translation(): void
    {
        // en-only page; vi has no slug → facade maps the service's null to '#'.
        $page = app('cms.content')->create([
            'type' => 'page', 'status' => 'published', 'locale' => 'en',
            'title' => 'English only', 'slug' => 'english-only', 'content' => '<p>x</p>',
        ]);

        $this->assertNull($this->service()->forResource($page, 'vi'));
        $this->assertSame('#', content_url($page, 'vi'));
    }

    public function test_term_url_delegates_to_the_one_service_byte_identically(): void
    {
        $category = $this->bilingualCategory();

        $this->assertSame($this->service()->forResource($category, 'vi'), term_url($category, 'vi'));
        $this->assertSame('/category/news', term_url($category, 'en'));
        $this->assertSame('/vi/danh-muc/tin-tuc', term_url($category, 'vi'));
    }

    // ── descriptors / resolvers stay facts-only ────────────────────────────────

    public function test_route_descriptor_carries_routing_facts_only(): void
    {
        $descriptor = new RouteDescriptor(
            resolverKey: 'cms.page',
            canonicalType: 'page',
            canonicalId: 1,
            canonicalPath: '/blog/x',
        );

        $keys = array_keys($descriptor->toArray());

        foreach (['url', 'href', 'absolute_url', 'signature', 'prefix', 'domain'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }

        // A canonical path is prefix-free and never an absolute URL.
        $this->assertStringStartsNotWith('http', (string) $descriptor->canonicalPath);
        $this->assertStringStartsNotWith('/vi', (string) $descriptor->canonicalPath);
        $this->assertStringStartsNotWith('/en', (string) $descriptor->canonicalPath);
    }

    // ── Core imports no plugin classes ─────────────────────────────────────────

    public function test_core_source_imports_no_plugin_classes(): void
    {
        $offenders = [];

        foreach ($this->corePhpFiles() as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/^use\s+Plugins\\\\/m', $source)) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'Core must not import plugin classes.');
    }

    // ── Preview + content-URL services never reach for the legacy helpers ──────

    public function test_url_services_do_not_call_the_legacy_helpers(): void
    {
        $files = [
            base_path('packages/thenguyen/cms-core/src/Services/PreviewUrlService.php'),
            base_path('packages/thenguyen/cms-core/src/Services/LocalizedContentUrlService.php'),
        ];

        foreach ($files as $file) {
            // Strip comments/docblocks so only actual invocations are inspected
            // (these services legitimately *document* the helpers they replace).
            $source = php_strip_whitespace($file);
            $this->assertStringNotContainsString('content_url(', $source, $file.' must not call content_url().');
            $this->assertStringNotContainsString('term_url(', $source, $file.' must not call term_url().');
        }
    }

    // ── a third-party resource plugs in with no Core conditional ───────────────

    public function test_a_registered_third_party_resolver_is_consumed_without_core_changes(): void
    {
        /** @var LocalizedResourceResolverRegistry $registry */
        $registry = app('cms.localization.resolvers');
        $registry->register($this->fakeResolver());

        $context = new LocalizationContext('acme.widget');

        // The service resolves the fake resource purely through the registry.
        $this->assertSame('/vi/acme/gadget', $this->service()->forResource(new \stdClass, 'vi', $context));
        $this->assertSame('/acme/gadget', $this->service()->forResource(new \stdClass, 'en', $context));
    }

    private function fakeResolver(): LocalizedResourceResolverContract
    {
        return new class implements LocalizedResourceResolverContract
        {
            public function key(): string
            {
                return 'acme.widget';
            }

            public function priority(): int
            {
                return 100;
            }

            public function supports(LocalizationContext $context): bool
            {
                return $context->type === 'acme.widget';
            }

            public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
            {
                return new RouteDescriptor(
                    resolverKey: 'acme.widget',
                    canonicalType: 'acme.widget',
                    canonicalId: 1,
                    canonicalPath: '/acme/gadget',
                );
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function corePhpFiles(): array
    {
        $root = base_path('packages/thenguyen/cms-core/src');
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }

        return $files;
    }
}
