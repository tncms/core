<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Support\CmsInfo;
use Throwable;

class CmsInfoWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.cms-info';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $siteName = null;
        $adminBrandName = null;
        $settingsAvailable = false;
        $settingsCached = false;

        try {
            if (Schema::hasTable('cms_settings')) {
                $manager = app('cms.settings');
                $siteName = $manager->get('general.site_name');
                $adminBrandName = $manager->get('admin.brand_name');
                $settingsAvailable = true;
                $settingsCached = Cache::has('cms.settings.autoload');
            }
        } catch (Throwable) {
            $settingsAvailable = false;
        }

        return [
            'cmsName' => CmsInfo::name(),
            'cmsVersion' => CmsInfo::version(),
            'laravelVersion' => app()->version(),
            'phpVersion' => PHP_VERSION,
            'activeTheme' => CmsInfo::activeTheme(),
            'deploymentMode' => CmsInfo::deploymentMode(),
            'basePath' => CmsInfo::basePath(),
            'publicPath' => CmsInfo::publicPath(),
            'siteName' => $siteName,
            'adminBrandName' => $adminBrandName,
            'settingsAvailable' => $settingsAvailable,
            'settingsCached' => $settingsCached,
        ];
    }
}
