<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.0.0-beta.7.1.16 — a small forward-release schema change. Its presence in a
 * newer Core release lets the /upgrade migration stage be certified end-to-end:
 * after a v7.1.15 → v7.1.16 upgrade this table must exist on the live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_upgrade_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('marker')->default('v1.0.0-beta.7.1.16');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_upgrade_probe');
    }
};
