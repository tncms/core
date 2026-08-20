<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('taxonomy_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->integer('count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('taxonomy_id')
                ->references('id')
                ->on('cms_taxonomies')
                ->cascadeOnDelete();

            $table->foreign('parent_id')
                ->references('id')
                ->on('cms_terms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_terms');
    }
};
