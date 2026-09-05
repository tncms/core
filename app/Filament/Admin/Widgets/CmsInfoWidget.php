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
            'activeTheme' => $this->activeThemeLabel(),
            'deploymentMode' => CmsInfo::deploymentMode(),
            'basePath' => CmsInfo::basePath(),
            'publicPath' => CmsInfo::publicPath(),
            'siteName' => $siteName,
            'adminBrandName' => $adminBrandName,
            'settingsAvailable' => $settingsAvailable,
            'settingsCached' => $settingsCached,
        ];
    }

    /**
     * Human-friendly committed active theme for the environment summary, e.g.
     * "Ngo Hoang Nguyen (ngohoangnguyen)". Both the name and the slug derive from
     * the single canonical authority (ThemeManager). Falls back to the bare slug
     * from {@see CmsInfo::activeTheme()} when the theme service is unavailable.
     */
    private function activeThemeLabel(): string
    {
        $slug = CmsInfo::activeTheme();

        try {
            $theme = app('cms.theme')->active();

            if ($theme !== null) {
                return $theme->name.' ('.$theme->slug.')';
            }
        } catch (Throwable) {
            // Fall through to the bare slug below.
        }

        return $slug;
    }
}
