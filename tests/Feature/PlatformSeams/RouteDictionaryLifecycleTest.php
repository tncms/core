<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\PlatformSeams\Concerns\PlatformSeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionaryLifecycleException;
use TheNguyen\CMS\Localization\Dictionary\Composition\PluginRouteDictionarySource;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionaryComposer;
use TheNguyen\CMS\Localization\Dictionary\Composition\RouteDictionarySourceRegistry;
use TheNguyen\CMS\Localization\Dictionary\RouteKey;
use TheNguyen\CMS\Localization\Routing\LocalizedRouteRegistrar;

/**
 * CORE-PLATFORM-SEAMS-R2 Gate C — deterministic dictionary lifecycle:
 * REGISTRATION → VALIDATION → COMPOSITION → FINALIZATION → ROUTE CONSUMPTION.
 *
 *   A reads the dictionary in register()     → A fails alone (lifecycle exception), serves no routes
 *   B registers a valid source later         → accepted, routes served
 *   C registers a segment conflicting with B → B and C both refused (no last-writer-wins)
 *   D is inactive                            → contributes nothing
 *   search                                   → consumes the finalized dictionary
 */
final class RouteDictionaryLifecycleTest extends TestCase
{
    use PlatformSeamFixtures;
    use RefreshDatabase;

    private const READ_EARLY = '$app->make(\'cms.localization.dictionary\'); self::$log[] = \'read-ok\';';

    protected function setUp(): void
    {
        parent::setUp();
        app('cms.language')->create(['code' => 'vi', 'name' => 'Vietnamese', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]);
        app('cms.language')->create(['code' => 'en', 'name' => 'English', 'is_default' => false, 'is_active' => true, 'sort_order' => 2]);
        $this->useSeamPluginsRoot();
    }

    /**
     * One simulated request boot: fresh dictionary state, an empty route table, and
     * the fixture plugins' providers forgotten so ExtensionManager registers them
     * again (a real process starts with none registered).
     */
    private function freshBootState(): void
    {
        foreach ([RouteDictionarySourceRegistry::class, RouteDictionaryComposer::class, 'cms.localization.dictionary', 'cms.localized_routes'] as $id) {
            app()->forgetInstance($id);
        }
        app('router')->setRoutes(new \Illuminate\Routing\RouteCollection);

        foreach (['serviceProviders', 'loadedProviders'] as $property) {
            $ref = new \ReflectionProperty(app(), $property);
            $value = array_filter(
                $ref->getValue(app()),
                static fn ($v, $k) => ! str_starts_with(is_object($v) ? $v::class : (string) $k, 'Plugins\\Life'),
                ARRAY_FILTER_USE_BOTH,
            );
            $ref->setValue(app(), $property === 'serviceProviders' ? array_values($value) : $value);
        }
    }

    private function register(string $slug, array $segments): string
    {
        return 'self::$log[] = \'register\'; $app->make(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource(\''.$slug.'\', '.var_export($segments, true).'));';
    }

    private function provider(string $slug): string
    {
        return 'Plugins\\'.Str::studly($slug).'\\'.Str::studly($slug).'ServiceProvider';
    }

    /** @param  list<string>  $order */
    private function bootInOrder(array $order): array
    {
        $this->freshBootState();
        app('cms.settings')->set('extensions.active_plugins', $order);
        settings()->clearCache();

        foreach (['life-a', 'life-b', 'life-c', 'life-d'] as $slug) {
            if (class_exists($this->provider($slug), false)) {
                $this->provider($slug)::$log = [];
            }
        }

        app('cms.extension')->flushRegistry();
        app('cms.extension')->bootActivePlugins();
        app('cms.localized_routes')->finalize();
        app('router')->getRoutes()->refreshNameLookups();

        $registry = app(RouteDictionarySourceRegistry::class);
        $dictionary = app('cms.localization.dictionary');

        return [
            'a_log' => class_exists($this->provider('life-a'), false) ? $this->provider('life-a')::$log : null,
            'b_routes' => Route::getRoutes()->getByName('lifecycle.life-b') !== null,
            'a_routes' => Route::getRoutes()->getByName('lifecycle.life-a') !== null,
            'c_routes' => Route::getRoutes()->getByName('lifecycle.life-c') !== null,
            'refused' => array_keys($registry->refusals()),
            'b_segment' => $dictionary->segmentFor(RouteKey::of('life-b-key'), 'en')?->value,
            'e_segment' => $dictionary->segmentFor(RouteKey::of('life-e-key'), 'en')?->value,
            'd_segment' => $dictionary->segmentFor(RouteKey::of('life-d-key'), 'en')?->value,
        ];
    }

    public function test_early_reader_fails_alone_and_the_result_is_order_independent(): void
    {
        $this->writeLifecyclePlugin('life-a', self::READ_EARLY);
        $this->writeLifecyclePlugin('life-b', $this->register('life-b', ['life-b-key' => ['en' => 'b-en'], 'life-e-key' => ['en' => 'e-en']]));
        $this->writeLifecyclePlugin('life-c', $this->register('life-c', ['life-c-key' => ['en' => 'b-en']]));
        $this->writeLifecyclePlugin('life-d', $this->register('life-d', ['life-d-key' => ['en' => 'd-en']]));
        app('cms.extension')->flushRegistry();

        $results = [];

        foreach ([['life-a', 'life-b', 'life-c'], ['life-c', 'life-b', 'life-a'], ['life-b', 'life-a', 'life-c']] as $order) {
            $results[] = $this->bootInOrder($order);
        }

        $this->assertSame($results[0], $results[1]);
        $this->assertSame($results[0], $results[2]);

        $r = $results[0];
        $this->assertSame([], $r['a_log'], 'A never completed its early read');
        $this->assertFalse($r['a_routes'], 'A (failed registration) serves no routes');
        $this->assertSame(['plugin:life-b', 'plugin:life-c'], $r['refused'], 'B/C segment collision: both refused');
        $this->assertFalse($r['b_routes']);
        $this->assertFalse($r['c_routes']);
        $this->assertNull($r['d_segment'], 'inactive D contributes nothing');
    }

    public function test_without_a_conflict_the_late_registrant_is_served_and_search_reads_the_finalized_map(): void
    {
        $this->writeLifecyclePlugin('life-a', self::READ_EARLY);
        $this->writeLifecyclePlugin('life-b', $this->register('life-b', ['life-b-key' => ['en' => 'b-en'], 'life-e-key' => ['en' => 'e-en']]));
        app('cms.extension')->flushRegistry();

        foreach ([['life-a', 'life-b'], ['life-b', 'life-a']] as $order) {
            $this->freshBootState();
            (new \App\Providers\SearchServiceProvider(app()))->boot(); // host search boots first, defers
            $r = $this->bootInOrder($order);

            $this->assertSame('b-en', $r['b_segment']);
            $this->assertTrue($r['b_routes']);
            $this->assertFalse($r['a_routes']);
            $this->assertSame([], $r['refused']);
        }
    }

    public function test_reads_are_refused_while_registration_is_open_and_allowed_after_finalization(): void
    {
        $this->freshBootState();
        $registrar = app('cms.localized_routes');
        $registrar->openRegistration();

        try {
            app('cms.localization.dictionary');
            $this->fail('early read composed the dictionary');
        } catch (RouteDictionaryLifecycleException $e) {
            $this->assertStringContainsString('plugin registration', $e->getMessage());
        }

        $this->assertFalse(app(RouteDictionarySourceRegistry::class)->isLocked(), 'the refused read did not lock the registry');
        app(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource('late-ok', ['late-key' => ['en' => 'late-en']]));

        $registrar->finalize();
        $this->assertSame('late-en', app('cms.localization.dictionary')->projectSegment(RouteKey::of('late-key'), 'en'));
        $this->assertFalse($registrar->isRegistrationOpen());
    }

    public function test_reads_while_the_application_is_booting_are_refused(): void
    {
        $registrar = new LocalizedRouteRegistrar(new RouteDictionarySourceRegistry);

        $this->expectException(RouteDictionaryLifecycleException::class);
        $this->expectExceptionMessage('application boot');
        $registrar->assertReadable(appBooted: false);
    }

    public function test_post_boot_out_of_band_read_finalizes_implicitly(): void
    {
        $this->freshBootState();
        app('cms.localization.dictionary');

        $this->assertTrue(app('cms.localized_routes')->isFinalized());
        $this->expectException(\TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionarySourceRegistryException::class);
        app(RouteDictionarySourceRegistry::class)->register(new PluginRouteDictionarySource('too-late', ['x-key' => ['en' => 'x']]));
    }

    public function test_no_duplicate_routes_after_finalize_is_called_twice(): void
    {
        $this->writeLifecyclePlugin('life-b', $this->register('life-b', ['life-b-key' => ['en' => 'b-en'], 'life-e-key' => ['en' => 'e-en']]));
        app('cms.extension')->flushRegistry();
        $this->bootInOrder(['life-b']);
        $before = count(app('router')->getRoutes()->getRoutes());

        app('cms.localized_routes')->finalize();
        $this->assertSame($before, count(app('router')->getRoutes()->getRoutes()));
    }
}
