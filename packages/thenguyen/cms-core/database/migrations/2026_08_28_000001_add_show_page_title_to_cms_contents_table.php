<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PB-FREE-LIBRARY-DESIGN-1-E-H1 — page title visibility contract.
 *
 * Additive, backward-compatible column on cms_contents: whether the visible
 * page/entry title is rendered above the content. Defaults to true so every
 * existing row and every fresh install keeps showing its title (the pre-contract
 * behaviour). This is a base-entity presentation invariant — NOT per translation:
 * hiding the visible title never removes or blanks the stored title, SEO title,
 * canonical URL, navigation label, or Admin label. Idempotent: the column is only
 * added when missing, and rollback only drops the one it owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('cms_contents', 'show_page_title')) {
                $table->boolean('show_page_title')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            if (Schema::hasColumn('cms_contents', 'show_page_title')) {
                $table->dropColumn('show_page_title');
            }
        });
    }
};
