<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_media', function (Blueprint $table): void {
            $table->id();
            $table->string('disk')->default('public')->index();
            $table->string('folder')->nullable()->index();
            $table->string('filename');
            $table->string('original_filename');
            $table->string('extension')->nullable();
            $table->string('mime_type')->nullable()->index();
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('path');
            $table->string('url');
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_media');
    }
};
