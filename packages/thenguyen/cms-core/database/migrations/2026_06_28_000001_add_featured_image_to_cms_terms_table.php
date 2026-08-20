<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Featured image for taxonomy terms (v1.0.0-beta.7.1.9).
 *
 * Lives on cms_terms (NOT cms_term_translations) because a term's image is
 * GLOBAL — shared across every locale, exactly like parent_id. Translations
 * localize labels/description only. Stored as a URL string written by the
 * existing Media Picker, mirroring cms_contents.featured_image.
 *
 * Only meaningful for hierarchical taxonomies (categories and future custom
 * hierarchical taxonomies); flat taxonomies (tags) simply leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_terms') || Schema::hasColumn('cms_terms', 'featured_image')) {
            return;
        }

        Schema::table('cms_terms', function (Blueprint $table): void {
            $table->string('featured_image')->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cms_terms', 'featured_image')) {
            return;
        }

        Schema::table('cms_terms', function (Blueprint $table): void {
            $table->dropColumn('featured_image');
        });
    }
};
