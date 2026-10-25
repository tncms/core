<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trusted external script approvals (CORE-EXTENSION-TRUST S1, ADR D-2).
 *
 * One row per (plugin slug, script key): the fingerprint an administrator last
 * approved, and whether that approval is still in force. A script is effective
 * only while its plugin is active, the row is `approved`, and the row's
 * fingerprint equals the CURRENT declaration fingerprint. Actor ids are kept
 * without a foreign key so the audit survives user deletion. Additive; never
 * touches existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_trusted_script_approvals')) {
            return;
        }

        Schema::create('cms_trusted_script_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_slug', 100);
            $table->string('script_key', 64);
            $table->char('fingerprint', 64);
            $table->text('url');
            $table->string('status', 16);                    // approved | revoked
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 32)->nullable(); // admin | plugin_deactivated
            $table->timestamps();

            $table->unique(['owner_slug', 'script_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_trusted_script_approvals');
    }
};
