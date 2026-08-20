<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Services\PluginLifecycleManager;

/**
 * Deactivate an active plugin by slug. Generic — works for any plugin.
 *
 * Disables the plugin only. Migrations are NEVER rolled back on deactivate, so a
 * plugin's data survives a disable/enable cycle.
 */
class PluginDeactivateCommand extends Command
{
    protected $signature = 'plugin:deactivate {slug : The plugin slug}';

    protected $description = 'Deactivate a TN CMS plugin by slug (does not roll back migrations).';

    public function handle(ExtensionManager $extension, PluginLifecycleManager $lifecycle): int
    {
        $slug = (string) $this->argument('slug');

        if (! $extension->isPluginActive($slug)) {
            $this->info("Plugin [{$slug}] is not active.");

            return self::SUCCESS;
        }

        $lifecycle->deactivate($slug);

        $this->info("Plugin [{$slug}] deactivated. Clear caches if needed.");

        return self::SUCCESS;
    }
}
