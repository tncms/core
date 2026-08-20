<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widget Foundation (v1.0.0-beta.7).
 *
 * A widget area (a.k.a. sidebar) is a named slot a theme exposes for widgets —
 * e.g. "sidebar-blog", "footer-1". Core registers a baseline set; themes and
 * plugins may register more. The registry is in code; this table is the
 * persisted mirror (see WidgetManager::syncAreas()) so the admin can list and
 * assign widgets to stable area ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_widget_areas', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            // Origin of the area: 'core', 'theme', or 'plugin' (nullable for safety).
            $table->string('source')->nullable();
            // The theme/plugin slug that registered it, when source is theme/plugin.
            $table->string('source_slug')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_widget_areas');
    }
};
