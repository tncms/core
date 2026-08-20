<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9C-A — content metrics foundation.
 *
 * Additive columns on cms_contents so future query modes (Latest / Most Viewed /
 * Most Commented / Featured) have a stable backing store. Idempotent: each column
 * is only added when missing, and rollback only drops the ones it owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('cms_contents', 'views_count')) {
                $table->unsignedBigInteger('views_count')->default(0)->index();
            }

            if (! Schema::hasColumn('cms_contents', 'comments_count')) {
                $table->unsignedBigInteger('comments_count')->default(0)->index();
            }

            if (! Schema::hasColumn('cms_contents', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            foreach (['views_count', 'comments_count', 'is_featured'] as $column) {
                if (Schema::hasColumn('cms_contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
