<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use TheNguyen\CMS\Services\DemoImporter;

/**
 * Reset a previously imported demo package, restoring the pre-import
 * settings/layout snapshot the core wrote.
 *
 *   php artisan tncms:demo:reset {owner} {slug} [--force]
 *
 * This is destructive (it overwrites the current theme settings / homepage
 * layout with the snapshot), so it is guarded by an interactive confirmation
 * unless --force is given.
 */
class DemoResetCommand extends AbstractDemoCommand
{
    protected $signature = 'tncms:demo:reset
        {owner : The owning theme or plugin slug}
        {slug : The demo package slug}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Reset a previously imported demo package.';

    public function handle(DemoImporter $importer): int
    {
        $package = $this->resolvePackage($importer);

        if ($package === null) {
            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Reset demo '{$package->owner}/{$package->slug}'? This restores the pre-import settings/layout.")) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        return $this->render($importer->reset($package));
    }
}
