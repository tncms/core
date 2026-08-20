<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('menu_id')->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('type')->default('custom');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->string('url')->nullable();
            $table->string('target')->default('_self');
            $table->string('css_class')->nullable();
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('menu_id')
                ->references('id')
                ->on('cms_menus')
                ->cascadeOnDelete();

            $table->foreign('parent_id')
                ->references('id')
                ->on('cms_menu_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menu_items');
    }
};
