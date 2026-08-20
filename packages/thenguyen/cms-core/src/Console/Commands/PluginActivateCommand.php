<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\PluginLifecycleManager;

/**
 * Activate a discovered plugin by slug. Generic — works for any plugin.
 *
 * Activation runs the full lifecycle: the plugin's database (migrations +
 * seeders) is installed BEFORE the plugin is marked active, so its pages never
 * 500 on a missing table. A migration failure leaves the plugin inactive.
 */
class PluginActivateCommand extends Command
{
    protected $signature = 'plugin:activate {slug : The plugin slug}';

    protected $description = 'Activate a TN CMS plugin by slug (installs its database first).';

    public function handle(PluginLifecycleManager $lifecycle): int
    {
        $slug = (string) $this->argument('slug');

        $result = $lifecycle->activate($slug);

        if (! $result->success) {
            $this->error("Could not activate plugin [{$slug}]: ".($result->error ?? $result->message));

            return self::FAILURE;
        }

        if ($result->alreadyActive) {
            $this->info("Plugin [{$slug}] is already active.");

            return self::SUCCESS;
        }

        if ($result->ranMigrations !== []) {
            $this->info('Installed '.count($result->ranMigrations).' migration(s).');
        }

        $this->info("Plugin [{$slug}] activated and its database is installed.");

        return self::SUCCESS;
    }
}
