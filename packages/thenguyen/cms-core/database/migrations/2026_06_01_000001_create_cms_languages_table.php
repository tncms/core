<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20);
            $table->string('locale', 20)->nullable();
            $table->string('name');
            $table->string('native_name')->nullable();
            $table->string('flag')->nullable();
            $table->string('direction', 3)->default('ltr');
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('sort_order')->default(0)->index();
            $table->timestamps();

            $table->unique('code', 'cms_languages_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_languages');
    }
};
