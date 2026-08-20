<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use TheNguyen\CMS\Services\DemoImporter;

/**
 * List every discovered demo package (installed themes + active plugins).
 *
 *   php artisan tncms:demo:list
 */
class DemoListCommand extends AbstractDemoCommand
{
    protected $signature = 'tncms:demo:list';

    protected $description = 'List discovered theme & plugin demo packages.';

    public function handle(DemoImporter $importer): int
    {
        $packages = $importer->discover();

        if ($packages === []) {
            $this->info('No demo packages found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($packages as $package) {
            $rows[] = [$package->type, $package->owner, $package->slug, $package->name, $package->version];
        }

        $this->table(['Type', 'Owner', 'Slug', 'Name', 'Version'], $rows);

        return self::SUCCESS;
    }
}
