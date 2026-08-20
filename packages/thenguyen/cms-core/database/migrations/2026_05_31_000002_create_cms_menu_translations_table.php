<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menu_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_id')->index();
            $table->string('locale', 20)->default('vi')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('menu_id')
                ->references('id')
                ->on('cms_menus')
                ->cascadeOnDelete();

            $table->unique(['menu_id', 'locale'], 'cms_menu_translations_menu_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_translations');
    }
};
