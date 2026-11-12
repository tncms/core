<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\TestCase;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1 migration contract, isolated
 * (CORE-SEO-RELEASE-HARDENING-R1). Running the migration's DDL inside the
 * RefreshDatabase transaction made MySQL commit implicitly, so this test's
 * fixtures leaked into later tests (the next test booted, and registered its
 * locale routes, from the committed rows). After this test the database is
 * rebuilt with migrate:fresh, so whatever the DDL committed is gone before
 * the next test boots, on every driver.
 */
final class PerItemRobotsMigrationTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: false);
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
        });
    }

    public function test_the_migration_is_additive_idempotent_and_preserves_owner_values(): void
    {
        $migration = require base_path('packages/thenguyen/cms-core/database/migrations/2026_11_04_000003_add_seo_robots_to_cms_contents_and_cms_terms.php');
        $post = $this->smPost('Gia tri', english: false, extra: ['seo_robots' => 'nofollow']);
        $term = app('cms.taxonomy')->createTerm('category', ['locale' => 'vi', 'name' => 'Gia tri the', 'slug' => Str::slug('Gia tri the'), 'seo_robots' => 'noindex']);

        $migration->up();
        $migration->up();
        $this->assertSame('nofollow', $post->fresh()->seo_robots, 're-running keeps owner values');
        $this->assertSame('noindex', $term->fresh()->seo_robots);

        $migration->down();
        $this->assertFalse(Schema::hasColumn('cms_contents', 'seo_robots'));
        $this->assertFalse(Schema::hasColumn('cms_terms', 'seo_robots'));
        $this->assertTrue(Schema::hasColumn('cms_contents', 'show_page_title'), 'down drops only its own column');
        $migration->down();

        $migration->up();
        $this->assertNull($post->fresh()->seo_robots, 'fresh column inherits');

        $response = $this->get((string) parse_url(content_url($post, 'vi'), PHP_URL_PATH));
        $response->assertOk();
        preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', (string) $response->getContent(), $m);
        $this->assertSame(['index,follow'], $m[1]);
    }
}
