<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\SettingsManager;

class ClearSettingsCacheCommand extends Command
{
    protected $signature = 'cms:settings-clear';

    protected $description = 'Clear TN CMS settings cache.';

    public function handle(SettingsManager $settings): int
    {
        $settings->clearCache();

        $this->info('TN CMS settings cache cleared.');

        return self::SUCCESS;
    }
}
