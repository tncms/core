<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_slugs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_type')->index();
            $table->unsignedBigInteger('reference_id')->index();
            $table->string('locale', 20)->default('vi')->index();
            $table->string('slug')->index();
            $table->string('prefix')->nullable()->index();
            $table->string('full_path')->index();
            $table->boolean('is_primary')->default(true);
            $table->timestamps();

            $table->unique(['locale', 'full_path'], 'cms_slugs_locale_full_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_slugs');
    }
};
