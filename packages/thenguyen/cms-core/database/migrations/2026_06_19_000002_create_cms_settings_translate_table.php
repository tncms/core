<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localized Settings Framework (v1.0.0-beta.6.2).
 *
 * A translation companion to cms_settings: a setting whose value differs per
 * language (site name, tagline, SEO meta defaults, maintenance title/message)
 * stores one row per (key, locale) here. cms_settings is left unchanged and
 * remains the global fallback forever — see SettingsManager::getLocalized().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_settings_translate', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->index();
            $table->string('locale', 20)->index();
            $table->longText('value')->nullable();
            $table->string('type')->default('string');
            $table->timestamps();

            $table->unique(['key', 'locale'], 'cms_settings_translate_key_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_settings_translate');
    }
};
