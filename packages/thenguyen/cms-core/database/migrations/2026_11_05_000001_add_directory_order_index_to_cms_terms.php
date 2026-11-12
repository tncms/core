<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CORE-P16-1 — schema-only, additive, idempotent.
 *
 * The taxonomy directory pages terms of one taxonomy in (sort_order, id) order. With this
 * composite index the database reads those rows in index order and stops after one page, instead
 * of scanning and sorting the whole taxonomy (measured: MySQL 8.4, 75k terms, browse page
 * ~70 ms → <1 ms; see docs/core-p16-taxonomy-directory-r1/11-performance.md). No data change.
 * down() drops only this index; the pre-existing taxonomy_id index is untouched.
 */
return new class extends Migration
{
    private const INDEX = 'cms_terms_taxonomy_sort_id_index';

    public function up(): void
    {
        if (! Schema::hasTable('cms_terms') || Schema::hasIndex('cms_terms', self::INDEX)) {
            return;
        }

        Schema::table('cms_terms', static function (Blueprint $table): void {
            $table->index(['taxonomy_id', 'sort_order', 'id'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cms_terms') || ! Schema::hasIndex('cms_terms', self::INDEX)) {
            return;
        }

        Schema::table('cms_terms', static function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
