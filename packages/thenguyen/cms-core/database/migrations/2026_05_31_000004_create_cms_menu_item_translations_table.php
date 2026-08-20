<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menu_item_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_item_id')->index();
            $table->string('locale', 20)->default('vi')->index();
            $table->string('title');
            $table->timestamps();

            $table->foreign('menu_item_id')
                ->references('id')
                ->on('cms_menu_items')
                ->cascadeOnDelete();

            $table->unique(['menu_item_id', 'locale'], 'cms_menu_item_translations_item_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_item_translations');
    }
};
