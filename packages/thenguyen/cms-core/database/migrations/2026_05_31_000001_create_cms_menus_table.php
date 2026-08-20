<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_menus', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->string('location')->nullable()->index();
            $table->string('status')->default('active');
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug', 'cms_menus_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_menus');
    }
};
