<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widget Foundation (v1.0.0-beta.7).
 *
 * A widget *instance*: a concrete placement of a registered widget type
 * (widget_type) inside an area (area_id). The parent row stores the global,
 * non-localized settings + a fallback title; per-locale title/settings live in
 * cms_widget_translations. Rendered HTML is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_widgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('widget_type');
            $table->string('title')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('area_id')
                ->references('id')
                ->on('cms_widget_areas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_widgets');
    }
};
