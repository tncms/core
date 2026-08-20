<?php

declare(strict_types=1);

namespace Tests\Feature\Localization;

use TheNguyen\CMS\Localization\Dictionary\Administration\EntryOwnership;
use TheNguyen\CMS\Localization\Dictionary\Administration\Exceptions\RouteDictionaryAdministrationException;
use TheNguyen\CMS\Localization\Dictionary\Administration\RouteDictionaryManager;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\Persistence\ArrayFileRouteDictionaryStore;
use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use Tests\TestCase;

/**
 * P6.4 — the Project Dictionary administration service (CRUD, validation, ownership, reload,
 * immutability). Every test uses a TEMP store so the shipped config/cms-route-dictionary.php is
 * never touched.
 */
final class RouteDictionaryManagerTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir().'/tncms-route-dict-'.uniqid().'.php';
        $this->tempFiles[] = $path;

        return $path;
    }

    private function manager(?RouteDictionarySourceRegistry $registry = null, ?string $path = null): RouteDictionaryManager
    {
        $path ??= $this->tempPath();

        return new RouteDictionaryManager(
            new ArrayFileRouteDictionaryStore($path),
            $registry ?? new RouteDictionarySourceRegistry,
            app(),
            $path,
        );
    }

    public function test_defaults_have_no_project_overrides(): void
    {
        $manager = $this->manager();

        $this->assertSame(PlatformRouteDictionary::data(), $manager->stored());
        $this->assertSame([], $manager->projectOverrides());
    }

    public function test_overview_reports_core_ownership_for_seed_entries(): void
    {
        $rows = $this->manager()->overview();

        $products = array_values(array_filter($rows, static fn ($r): bool => $r['key'] === 'products' && $r['locale'] === 'vi'));

        $this->assertNotEmpty($products);
        $this->assertSame('san-pham', $products[0]['segment']);
        $this->assertSame(EntryOwnership::Core, $products[0]['owner']);
    }

    public function test_save_persists_a_project_override(): void
    {
        $path = $this->tempPath();
        $manager = $this->manager(path: $path);

        $manager->save(['products' => ['vi' => 'san-pham-moi']]);

        // The store holds the full base (seed with the override applied).
        $stored = (new ArrayFileRouteDictionaryStore($path))->load();
        $this->assertSame('san-pham-moi', $stored['products']['vi']);
        $this->assertSame('the', $stored['tag']['vi']); // untouched seed entry preserved

        // Ownership + project-override view reflect exactly the one edit.
        $this->assertSame(['products' => ['vi' => 'san-pham-moi']], $manager->projectOverrides());
        $this->assertSame(EntryOwnership::Project, $manager->ownerOf('products', 'vi'));
        $this->assertSame(EntryOwnership::Core, $manager->ownerOf('tag', 'vi'));
    }

    public function test_save_rebuilds_the_runtime_dictionary(): void
    {
        $path = $this->tempPath();
        $store = new ArrayFileRouteDictionaryStore($path);

        // Point the app's Runtime store at the temp store, then capture the current immutable
        // Dictionary so we can prove it is NOT mutated in place.
        app()->instance(RouteDictionaryStoreInterface::class, $store);
        app()->forgetInstance('cms.localization.dictionary');
        app()->forgetInstance(RouteDictionaryComposer::class);
        $before = app('cms.localization.dictionary');

        $manager = new RouteDictionaryManager($store, app(RouteDictionarySourceRegistry::class), app(), $path);
        $manager->save(['products' => ['vi' => 'san-pham-moi']]);

        $after = app('cms.localization.dictionary');

        $this->assertNotSame($before, $after, 'Reload must build a NEW immutable Dictionary, not patch the old one.');
        $this->assertSame('san-pham', $before->projectSegment(RouteKey::of('products'), 'vi'));
        $this->assertSame('san-pham-moi', $after->projectSegment(RouteKey::of('products'), 'vi'));
    }

    public function test_allows_overriding_a_core_reserved_key_segment(): void
    {
        // "products" is a reserved Platform key AND a seed key — overriding its localized segment
        // (a Project override) is allowed; only creating a NEW reserved key is rejected.
        $this->assertSame([], $this->manager()->validate(['products' => ['vi' => 'hang-hoa']]));
    }

    public function test_validation_rejects_a_new_reserved_key(): void
    {
        $errors = $this->manager()->validate(['contact' => ['vi' => 'lien-he']]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('reserved', strtolower(implode(' ', $errors)));
    }

    public function test_validation_rejects_an_invalid_route_key(): void
    {
        $this->assertNotEmpty($this->manager()->validate(['Bad Key' => ['vi' => 'hop-le']]));
    }

    public function test_validation_rejects_an_invalid_segment(): void
    {
        $this->assertNotEmpty($this->manager()->validate(['event' => ['vi' => 'Su Kien']]));
    }

    public function test_validation_rejects_a_cross_key_collision(): void
    {
        // "products" vi is "san-pham"; overriding "category" vi to "san-pham" collides.
        $errors = $this->manager()->validate(['category' => ['vi' => 'san-pham']]);

        $this->assertNotEmpty($errors);
    }

    public function test_validation_rejects_overriding_a_plugin_owned_segment(): void
    {
        $registry = new RouteDictionarySourceRegistry;
        $registry->register(new PluginRouteDictionarySource('example-plugin', ['faq' => ['vi' => 'cau-hoi-thuong-gap']]));

        $errors = $this->manager($registry)->validate(['faq' => ['vi' => 'khac-di']]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('plugin', strtolower(implode(' ', $errors)));
    }

    public function test_save_throws_and_persists_nothing_on_invalid_input(): void
    {
        $path = $this->tempPath();
        $manager = $this->manager(path: $path);

        try {
            $manager->save(['event' => ['vi' => 'Su Kien']]);
            $this->fail('Expected validation to reject the invalid segment.');
        } catch (RouteDictionaryAdministrationException $e) {
            $this->assertNotEmpty($e->errors);
        }

        $this->assertFalse(is_file($path), 'Nothing must be persisted when validation fails.');
    }
}
