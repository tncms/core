<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.0.0-beta.7.1.17 — the forward-release schema change certified by the
 * baseline (v7.1.16) → target (v7.1.17) upgrade: this table is absent on the
 * baseline and must exist after the /upgrade migration stage runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_upgrade_probe2', function (Blueprint $table): void {
            $table->id();
            $table->string('marker')->default('v1.0.0-beta.7.1.17');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_upgrade_probe2');
    }
};
