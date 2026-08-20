<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_term_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('term_id')->index();
            $table->string('locale', 20)->default('vi')->index();
            $table->string('name');
            $table->string('slug')->index();
            $table->text('description')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->timestamps();

            $table->foreign('term_id')
                ->references('id')
                ->on('cms_terms')
                ->cascadeOnDelete();

            $table->unique(['locale', 'slug'], 'cms_term_translations_locale_slug_unique');
            $table->unique(['term_id', 'locale'], 'cms_term_translations_term_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_term_translations');
    }
};
