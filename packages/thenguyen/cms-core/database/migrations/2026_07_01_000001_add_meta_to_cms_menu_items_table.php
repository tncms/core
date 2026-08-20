<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11B — additive menu-item metadata.
 *
 * Adds a nullable JSON `meta` column carrying presentational settings
 * (display type, mega columns/width, badge, description). Existing menus keep
 * working: a null `meta` normalizes to sensible defaults (display=normal) at
 * read time. No existing column is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_menu_items', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('cms_menu_items', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
