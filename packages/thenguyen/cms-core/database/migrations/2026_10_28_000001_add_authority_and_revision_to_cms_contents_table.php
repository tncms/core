<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured-content authority + shared revision (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * `managed_by` is the durable extension-ownership marker (plugin slug, NULL for
 * ordinary content); it is only ever written through ContentManager (claimOwnership / detachOwnership / create with claimingOwnership()).
 * `revision` is the shared optimistic-concurrency counter for the content row,
 * its translations and its term assignments. Additive and nullable/defaulted, so
 * existing rows stay ordinary content at revision 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('cms_contents', 'managed_by')) {
                $table->string('managed_by', 100)->nullable()->index();
            }

            if (! Schema::hasColumn('cms_contents', 'revision')) {
                $table->unsignedBigInteger('revision')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('cms_contents', function (Blueprint $table): void {
            if (Schema::hasColumn('cms_contents', 'managed_by')) {
                $table->dropIndex(['managed_by']);
                $table->dropColumn('managed_by');
            }

            if (Schema::hasColumn('cms_contents', 'revision')) {
                $table->dropColumn('revision');
            }
        });
    }
};
