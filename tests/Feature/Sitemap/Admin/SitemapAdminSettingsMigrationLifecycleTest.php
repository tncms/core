<?php

declare(strict_types=1);

namespace Tests\Feature\Sitemap\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CORE-HTML-SANITIZER-INTEGRATION-R2 / H2: the sitemap-settings data migration must be runnable by a
 * migrator that runs before the application has booted (providers registered, not booted), i.e. without
 * Eloquent's connection resolver. Core providers read cms_settings while booting, so a schema-first
 * consumer (e.g. an isolated extension test application) has to migrate before boot. The migration only
 * needs to invalidate the cached autoload map; it must not reload it through the Eloquent settings model.
 */
final class SitemapAdminSettingsMigrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'packages/thenguyen/cms-core/database/migrations/2026_11_04_000002_seed_sitemap_admin_settings.php';

    private const KEYS = ['sitemap_enabled', 'sitemap_excluded_types', 'sitemap_excluded_providers'];

    public function test_runs_without_an_eloquent_connection_resolver_and_invalidates_the_autoload_cache(): void
    {
        DB::table('cms_settings')->where('group', 'seo')->whereIn('key', self::KEYS)->delete();
        Cache::forever('cms.settings.autoload', ['stale' => true]);

        $resolver = Model::getConnectionResolver();
        Model::unsetConnectionResolver();
        try {
            $this->migration()->up();
        } finally {
            Model::setConnectionResolver($resolver);
        }

        $this->assertSame(3, DB::table('cms_settings')->where('group', 'seo')->whereIn('key', self::KEYS)->count());
        $this->assertFalse(Cache::has('cms.settings.autoload'), 'the stale autoload map must be dropped');
        $this->assertTrue(settings('seo.sitemap_enabled'));
        $this->assertSame([], settings('seo.sitemap_excluded_types'));
    }

    public function test_is_idempotent_and_never_overwrites_operator_values(): void
    {
        DB::table('cms_settings')->where('group', 'seo')->where('key', 'sitemap_enabled')->update(['value' => '0']);
        Cache::forever('cms.settings.autoload', ['kept' => true]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame(3, DB::table('cms_settings')->where('group', 'seo')->whereIn('key', self::KEYS)->count());
        $this->assertSame('0', DB::table('cms_settings')->where('group', 'seo')->where('key', 'sitemap_enabled')->value('value'));
        $this->assertSame(['kept' => true], Cache::get('cms.settings.autoload'), 'nothing inserted, so the cache is left alone');
    }

    /**
     * CORE-P16-1 §21 / R2 N3: a second writer inserts the row after this migrator's existence check
     * and before its insert (two concurrent migrators, or an app process writing the default). The
     * unique (group, key) index used to turn that into a duplicate-key failure that aborted the
     * upgrade. The migration must instead keep the existing row and finish.
     */
    public function test_a_row_inserted_concurrently_after_the_existence_check_does_not_abort_the_migration(): void
    {
        DB::table('cms_settings')->where('group', 'seo')->whereIn('key', self::KEYS)->delete();
        $raced = false;

        DB::listen(static function ($query) use (&$raced): void {
            if ($raced || ! str_contains($query->sql, 'exists') || ! in_array('sitemap_enabled', $query->bindings, true)) {
                return;
            }

            $raced = true;
            DB::table('cms_settings')->insert([
                'group' => 'seo', 'key' => 'sitemap_enabled', 'value' => '0', 'type' => 'boolean',
                'is_public' => false, 'autoload' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->migration()->up();

        $this->assertTrue($raced, 'the concurrent insert was simulated');
        $this->assertSame(3, DB::table('cms_settings')->where('group', 'seo')->whereIn('key', self::KEYS)->count());
        $this->assertSame('0', DB::table('cms_settings')->where('group', 'seo')->where('key', 'sitemap_enabled')->value('value'), 'the concurrent writer wins; never overwritten');
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
