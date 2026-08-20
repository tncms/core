<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_content_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id')->index();
            $table->string('locale', 20)->default('vi')->index();
            $table->string('title')->nullable();
            $table->string('slug')->index();
            $table->text('excerpt')->nullable();
            $table->longText('content')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('meta_keywords')->nullable();
            $table->timestamps();

            $table->foreign('content_id')
                ->references('id')
                ->on('cms_contents')
                ->cascadeOnDelete();

            $table->unique(['locale', 'slug'], 'cms_content_translations_locale_slug_unique');
            $table->unique(['content_id', 'locale'], 'cms_content_translations_content_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_content_translations');
    }
};
