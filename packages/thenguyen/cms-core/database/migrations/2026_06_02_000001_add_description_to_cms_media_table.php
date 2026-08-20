<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_media') || Schema::hasColumn('cms_media', 'description')) {
            return;
        }

        Schema::table('cms_media', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cms_media') || ! Schema::hasColumn('cms_media', 'description')) {
            return;
        }

        Schema::table('cms_media', function (Blueprint $table): void {
            $table->dropColumn('description');
        });
    }
};
