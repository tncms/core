<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use TheNguyen\CMS\Content\Extension\LegacyOwnershipReconciler;

/**
 * Fold a development-only CONTENT-EXT `cms_content_ownership` table into the
 * single authority (CORE-UNIFIED-OWNERSHIP-R1). A no-op where that table does
 * not exist (every released installation).
 *
 * Conflicts (a different managed_by already set, orphans, unknown values)
 * abort the migration with a report and change nothing. Otherwise the mapping
 * is applied idempotently and audited; the legacy table is KEPT (unread) until
 * an operator verifies and drops it with
 * `cms:content-ownership:reconcile --drop-legacy --confirm-backup`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reconciler = new LegacyOwnershipReconciler;

        if (! $reconciler->legacyTableExists()) {
            return;
        }

        $report = $reconciler->plan();

        if ($report['conflicts'] !== []) {
            $path = $reconciler->writeReport($report);

            throw new RuntimeException("Legacy content ownership conflicts: nothing was changed. Review {$path} and resolve them with cms:content-owner, then migrate again.");
        }

        $reconciler->apply();
    }

    public function down(): void
    {
        // Ownership written here is the single authority now; it is not
        // reverted automatically (see CORE-UNIFIED-OWNERSHIP-R1-UPGRADE-ROLLBACK).
    }
};
