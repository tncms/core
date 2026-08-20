<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widget Foundation (v1.0.0-beta.7).
 *
 * Per-locale title + localized settings for a widget instance. The parent
 * cms_widgets row holds global settings; this table holds only the values that
 * differ per language (title, text, html, …). Resolution order at render time
 * is: requested locale → CMS default locale → parent → widget schema defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_widget_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('widget_id');
            $table->string('locale', 20)->index();
            $table->string('title')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['widget_id', 'locale'], 'cms_widget_translations_widget_locale_unique');

            $table->foreign('widget_id')
                ->references('id')
                ->on('cms_widgets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_widget_translations');
    }
};
