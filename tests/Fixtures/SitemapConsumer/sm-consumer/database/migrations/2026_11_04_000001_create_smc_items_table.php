<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smc_items', static function (Blueprint $table): void {
            $table->id();
            // kb_article | kb_category | lottery_draw | ec_product | ec_category
            $table->string('family', 32);
            $table->string('slug_vi', 191);
            $table->string('slug_en', 191)->nullable();
            $table->string('region', 32)->nullable();
            $table->date('draw_date')->nullable();
            $table->string('status', 16)->default('published');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['family', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smc_items');
    }
};
