<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make custom menu-item URLs locale-specific (v1.0.0-beta.6.1).
 *
 * Previously a menu item's URL lived only in the shared cms_menu_items.url
 * column, so saving one locale overwrote every other locale's URL. Custom URLs
 * now live per-locale alongside the title in cms_menu_item_translations.
 * Entity-linked items (page/post/category/tag) keep resolving their URL from
 * the localized slug and do not use this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_menu_item_translations', function (Blueprint $table): void {
            $table->string('url')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('cms_menu_item_translations', function (Blueprint $table): void {
            $table->dropColumn('url');
        });
    }
};
