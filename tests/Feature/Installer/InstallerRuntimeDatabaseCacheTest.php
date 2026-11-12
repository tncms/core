<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use TheNguyen\CMS\Services\InstallerManager;

/**
 * CORE-40-INSTALLER-INPUT-CLOSURE-R1 (A-INST-1): on a fresh host without .env the web installer boots with the
 * framework defaults — default DB connection `sqlite` (database/database.sqlite, which a shipped package does
 * not contain) and `CACHE_STORE=database`. Boot-time `RateLimiter::for()` (frontend auth + media limiters)
 * resolves the limiter, which resolves and memoizes the database cache store bound to that pre-install
 * connection. `InstallerManager::applyRuntimeDatabase()` then switched only the DB default to the wizard's
 * MySQL, so every cache read/forget during the same `POST /install/run` (migration 2026_11_04_000002's
 * settings clearCache(), CmsLanguageSeeder's settings()->has(), the error handler's tn_trans()) still hit the
 * missing sqlite file: HTTP 500 with a half-migrated database.
 *
 * The previous installer tests ran with CACHE_STORE=array and an already-configured DB, so the stale store
 * never existed. These tests recreate exactly that pre-install state.
 */
final class InstallerRuntimeDatabaseCacheTest extends TestCase
{
    private string $missingSqlite;

    protected function setUp(): void
    {
        parent::setUp();

        // The pre-install state of a shipped package: no .env, so Laravel's defaults apply.
        $this->missingSqlite = storage_path('framework/testing/a-inst-1-missing-'.bin2hex(random_bytes(4)).'.sqlite');
        config([
            'database.connections.preinstall_sqlite' => ['driver' => 'sqlite', 'database' => $this->missingSqlite, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'preinstall_sqlite',
            'cache.default' => 'database',
            'cache.limiter' => null,
            'cache.stores.database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => null],
        ]);
        Cache::forgetDriver(['database']);

        // What CmsServiceProvider boot does on every request: registering a named limiter resolves the
        // RateLimiter singleton, which resolves (and memoizes) the default cache store right now.
        $this->app->forgetInstance(RateLimiter::class);
        $this->app->make(RateLimiter::class)->for('a-inst-1-probe', static fn () => null);
    }

    protected function tearDown(): void
    {
        Cache::forgetDriver(['database']);
        DB::purge('mysql');
        DB::purge('preinstall_sqlite');
        $this->app->forgetInstance(RateLimiter::class);
        @unlink($this->missingSqlite);
        parent::tearDown();
    }

    /** @return array{host: string, port: string, database: string, username: string, password: string} */
    private function wizardDatabase(): array
    {
        // Never contacted: connections are lazy, and these tests only inspect which connection is bound.
        return ['host' => '127.0.0.1', 'port' => '1', 'database' => 'tncms_wizard_target', 'username' => 'wizard', 'password' => 'not-used'];
    }

    private function boundConnectionName(): string
    {
        $store = Cache::store()->getStore();
        $this->assertInstanceOf(DatabaseStore::class, $store);

        return $store->getConnection()->getName();
    }

    #[Test]
    public function the_precondition_is_real_the_cache_store_is_memoized_on_the_pre_install_connection(): void
    {
        $this->assertSame('preinstall_sqlite', $this->boundConnectionName());
        $this->assertFileDoesNotExist($this->missingSqlite);
    }

    #[Test]
    public function applying_the_runtime_database_rebinds_the_database_cache_store_to_it(): void
    {
        app(InstallerManager::class)->applyRuntimeDatabase($this->wizardDatabase());

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame(
            'mysql',
            $this->boundConnectionName(),
            'After the wizard database is applied, cache reads/forgets in the same request (migrations, seeders, settings) must use it, not the pre-install connection.',
        );
        $this->assertSame('tncms_wizard_target', Cache::store()->getStore()->getConnection()->getDatabaseName());
    }

    #[Test]
    public function the_settings_cache_no_longer_targets_the_missing_pre_install_database(): void
    {
        app(InstallerManager::class)->applyRuntimeDatabase($this->wizardDatabase());

        // Exactly the call migration 2026_11_04_000002 makes. Before the fix it threw "Database file at path [...]
        // does not exist" from the stale sqlite-bound store. Now it targets the (unreachable in this test) wizard
        // database, so any failure is a MySQL connection error, never the pre-install sqlite file.
        try {
            app('cms.settings')->clearCache();
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('sqlite', strtolower($e->getMessage()), 'clearCache() must not reach the pre-install sqlite store.');
        }
        $this->assertFileDoesNotExist($this->missingSqlite, 'The pre-install sqlite file must never be created as a side effect.');
    }

    #[Test]
    public function non_database_cache_stores_are_left_untouched_and_the_call_is_idempotent(): void
    {
        $array = Cache::store('array');
        $array->put('a-inst-1', 'kept', 60);

        $installer = app(InstallerManager::class);
        $installer->applyRuntimeDatabase($this->wizardDatabase());
        $installer->applyRuntimeDatabase($this->wizardDatabase());

        $this->assertSame($array, Cache::store('array'), 'Only database-backed stores are rebound.');
        $this->assertSame('kept', Cache::store('array')->get('a-inst-1'));
        $this->assertSame('mysql', $this->boundConnectionName());
    }
}
