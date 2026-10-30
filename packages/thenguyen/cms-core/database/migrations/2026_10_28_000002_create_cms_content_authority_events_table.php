<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for the structured-content ownership marker
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1). Every claim / detach writes one row in
 * the same transaction as the marker change. Never updated or deleted by Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_content_authority_events')) {
            return;
        }

        Schema::create('cms_content_authority_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id')->index();
            $table->string('action', 32);                    // claimed | detached
            $table->string('owner', 100);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('revision');
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_content_authority_events');
    }
};
