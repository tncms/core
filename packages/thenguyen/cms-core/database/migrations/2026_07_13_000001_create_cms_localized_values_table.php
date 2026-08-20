<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localized Storage & Field Infrastructure (Phase 8.1).
 *
 * Reusable, module-agnostic storage for localized field values (title, slug,
 * excerpt, content, SEO fields, …). One row per (namespace, key, locale):
 *
 *   - namespace : the TranslationKey namespace, e.g. 'content', 'ecommerce'
 *                 (stored '' — never null — so the unique index is consistent).
 *   - key       : the TranslationKey key, e.g. '123:title'.
 *   - locale    : BCP-47-ish code, e.g. 'en', 'vi'.
 *   - value     : the stored string for that locale.
 *
 * The unique (namespace, key, locale) index is the storage-side duplicate-locale
 * guard. Additive and standalone — it references no other table and no module
 * writes to it yet (Phase 8.1 is the platform layer only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_localized_values', function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 191)->default('')->index();
            $table->string('key', 191)->index();
            $table->string('locale', 20)->index();
            $table->longText('value')->nullable();
            $table->timestamps();

            $table->unique(['namespace', 'key', 'locale'], 'cms_localized_values_ns_key_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_localized_values');
    }
};
