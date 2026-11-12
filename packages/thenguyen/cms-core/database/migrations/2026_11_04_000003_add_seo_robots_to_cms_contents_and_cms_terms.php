<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-SEO-POLICY-AND-ADMIN-LOCALE-R1 — per-item robots restriction.
 *
 * Additive, nullable `seo_robots` on cms_contents (posts, pages) and cms_terms
 * (categories, tags). NULL means "inherit the site policy", so every existing
 * row and every fresh install keeps today's robots output and sitemap listing.
 * A value can only ADD noindex/nofollow (see ResourceRobotsPolicy); it never
 * loosens the site default or the site-wide noindex. Resource-level, not per
 * translation. Idempotent: added only when missing; rollback drops only the
 * columns this migration owns.
 */
return new class extends Migration
{
    private const TABLES = ['cms_contents', 'cms_terms'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'seo_robots')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->string('seo_robots', 32)->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'seo_robots')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('seo_robots');
                });
            }
        }
    }
};
