<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ownership transitions in the audit log (CORE-UNIFIED-OWNERSHIP-R1): the
 * previous and new owner, the trusted origin of the change (interactive,
 * extension, operator, reconcile) and a server-generated correlation id.
 * Additive and nullable: existing rows (claimed/detached) keep their meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_content_authority_events', function (Blueprint $table): void {
            if (! Schema::hasColumn('cms_content_authority_events', 'previous_owner')) {
                $table->string('previous_owner', 100)->nullable();
            }

            if (! Schema::hasColumn('cms_content_authority_events', 'new_owner')) {
                $table->string('new_owner', 100)->nullable();
            }

            if (! Schema::hasColumn('cms_content_authority_events', 'origin')) {
                $table->string('origin', 32)->nullable();
            }

            if (! Schema::hasColumn('cms_content_authority_events', 'correlation_id')) {
                $table->string('correlation_id', 64)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_content_authority_events', function (Blueprint $table): void {
            foreach (['previous_owner', 'new_owner', 'origin'] as $column) {
                if (Schema::hasColumn('cms_content_authority_events', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('cms_content_authority_events', 'correlation_id')) {
                $table->dropIndex(['correlation_id']);
                $table->dropColumn('correlation_id');
            }
        });
    }
};
