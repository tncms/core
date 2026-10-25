<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for trusted external script approvals
 * (CORE-EXTENSION-TRUST S1). Every approve / revoke writes one row in the same
 * transaction as the approval change. Additive; never updated or deleted by Core.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_trusted_script_approval_events')) {
            return;
        }

        Schema::create('cms_trusted_script_approval_events', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_slug', 100);
            $table->string('script_key', 64);
            $table->char('fingerprint', 64);
            $table->string('action', 32);                    // approved | revoked | revoked_plugin_deactivated
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['owner_slug', 'script_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_trusted_script_approval_events');
    }
};
