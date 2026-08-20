<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group')->nullable()->index();
            $table->string('key')->index();
            $table->longText('value')->nullable();
            $table->string('type')->default('string');
            $table->boolean('is_public')->default(false);
            $table->boolean('autoload')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key'], 'cms_settings_group_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_settings');
    }
};
