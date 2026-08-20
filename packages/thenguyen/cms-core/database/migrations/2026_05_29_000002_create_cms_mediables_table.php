<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_mediables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id')->index();
            $table->string('mediable_type')->index();
            $table->unsignedBigInteger('mediable_id')->index();
            $table->string('collection')->nullable()->index();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['media_id', 'mediable_type', 'mediable_id', 'collection'],
                'cms_mediables_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_mediables');
    }
};
