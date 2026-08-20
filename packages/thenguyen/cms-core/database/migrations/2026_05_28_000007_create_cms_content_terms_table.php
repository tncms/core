<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_content_terms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id')->index();
            $table->unsignedBigInteger('term_id')->index();
            $table->timestamps();

            $table->foreign('content_id')
                ->references('id')
                ->on('cms_contents')
                ->cascadeOnDelete();

            $table->foreign('term_id')
                ->references('id')
                ->on('cms_terms')
                ->cascadeOnDelete();

            $table->unique(['content_id', 'term_id'], 'cms_content_terms_content_term_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_content_terms');
    }
};
