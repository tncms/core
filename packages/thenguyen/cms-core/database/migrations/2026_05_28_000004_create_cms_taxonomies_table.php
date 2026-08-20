<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_taxonomies', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('content_type')->default('post')->index();
            $table->string('slug')->index();
            $table->boolean('hierarchical')->default(false);
            $table->boolean('is_core')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['content_type', 'slug'], 'cms_taxonomies_content_type_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_taxonomies');
    }
};
