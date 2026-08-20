<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_contents', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->default('draft')->index();
            $table->unsignedBigInteger('author_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('template')->nullable();
            $table->string('featured_image')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->string('comment_status')->default('closed');
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_contents');
    }
};
