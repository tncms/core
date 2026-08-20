<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use TheNguyen\CMS\Services\DemoImporter;

/**
 * Import (or re-import) a theme/plugin demo package.
 *
 *   php artisan tncms:demo:import {owner} {slug}
 *
 * `owner` is a theme slug (theme packages) or a plugin slug (plugin packages);
 * `slug` is the package slug. Imports are idempotent and reversible via
 * `tncms:demo:reset`.
 */
class DemoImportCommand extends AbstractDemoCommand
{
    protected $signature = 'tncms:demo:import
        {owner : The owning theme or plugin slug}
        {slug : The demo package slug}';

    protected $description = 'Import a theme/plugin demo package.';

    public function handle(DemoImporter $importer): int
    {
        $package = $this->resolvePackage($importer);

        if ($package === null) {
            return self::FAILURE;
        }

        return $this->render($importer->import($package));
    }
}
