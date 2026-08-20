<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Support\Plugin;

/**
 * List discovered plugins and whether each is active. Generic.
 */
class PluginListCommand extends Command
{
    protected $signature = 'plugin:list';

    protected $description = 'List discovered TN CMS plugins and their active state.';

    public function handle(ExtensionManager $extension): int
    {
        $plugins = $extension->plugins();

        if ($plugins === []) {
            $this->info('No plugins discovered.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Version', 'Active'],
            array_map(static fn (Plugin $p): array => [
                $p->slug,
                $p->name,
                $p->version,
                $extension->isPluginActive($p->slug) ? 'yes' : 'no',
            ], $plugins),
        );

        return self::SUCCESS;
    }
}
