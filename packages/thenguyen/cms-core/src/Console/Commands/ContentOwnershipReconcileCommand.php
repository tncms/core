<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Content\Extension\LegacyOwnershipReconciler;

/**
 * Operator procedure for a development database that ran the unreleased
 * CONTENT-EXT `cms_content_ownership` table (CORE-UNIFIED-OWNERSHIP-R1).
 *
 *   (no option)          dry run: report what would be adopted and every conflict
 *   --apply              map into managed_by + owner policies (refuses on conflicts)
 *   --drop-legacy --confirm-backup
 *                        drop the legacy table, only when every row is verified
 */
class ContentOwnershipReconcileCommand extends Command
{
    protected $signature = 'cms:content-ownership:reconcile
        {--apply : Apply the mapping}
        {--drop-legacy : Drop the verified legacy table}
        {--confirm-backup : Confirm a database backup exists (required with --drop-legacy)}';

    protected $description = 'Reconcile a legacy cms_content_ownership table into cms_contents.managed_by';

    public function handle(): int
    {
        $reconciler = new LegacyOwnershipReconciler;

        if (! $reconciler->legacyTableExists()) {
            $this->info('No legacy cms_content_ownership table: nothing to reconcile.');

            return self::SUCCESS;
        }

        $plan = $reconciler->plan();
        $this->line(sprintf('legacy_rows=%d to_adopt=%d already_consistent=%d conflicts=%d',
            $plan['legacy_rows'], $plan['to_adopt'], $plan['already_consistent'], count($plan['conflicts'])));

        if ($plan['conflicts'] !== []) {
            $this->error('Conflicts found; nothing was changed. Report: '.$reconciler->writeReport($plan));

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $result = $reconciler->apply();
            $this->info(sprintf('adopted=%d policies_created=%d', $result['adopted'], $result['policies_created']));
        }

        if ($this->option('drop-legacy')) {
            if (! $this->option('confirm-backup')) {
                $this->error('--drop-legacy requires --confirm-backup (take a database backup first).');

                return self::FAILURE;
            }

            $unverified = $reconciler->verify();

            if ($unverified !== []) {
                $this->error(count($unverified).' legacy rows are not reflected in managed_by; the legacy table was kept.');

                return self::FAILURE;
            }

            $reconciler->dropLegacy();
            $this->info('Legacy cms_content_ownership table dropped.');
        }

        return self::SUCCESS;
    }
}
