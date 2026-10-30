<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-content owner policy (CORE-UNIFIED-OWNERSHIP-R1).
 *
 * The owning plugin lives ONLY in cms_contents.managed_by. This table holds
 * what an owner (or operator) decided about managed content — the fallback
 * applied while the owner is inactive or missing and the owner's persisted
 * availability — and deliberately has no owner column. A row is meaningful
 * only while managed_by is set; Core deletes it on release, detach and
 * transfer. A managed row without a policy row uses the defaults
 * (available, core_render), which is how pre-R1 managed content behaved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_content_owner_policies')) {
            return;
        }

        Schema::create('cms_content_owner_policies', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_id')->primary();
            $table->string('fallback_policy', 32);                         // core_render | hide_404 | temporary_503
            $table->string('availability', 32)->default('available');      // available | unavailable | gone | temporarily_unavailable
            $table->unsignedInteger('retry_after')->nullable();            // seconds, 503 only
            $table->boolean('operator_assigned')->default(false);          // fallback chosen by an operator
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('cms_contents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_content_owner_policies');
    }
};
