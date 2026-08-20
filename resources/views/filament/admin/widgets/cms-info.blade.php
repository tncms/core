<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            {{ $siteName ?: $cmsName }}
        </x-slot>

        <x-slot name="description">
            {{ tn_trans('CMS environment overview') }}
        </x-slot>

        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Site name (settings)') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $siteName ?: '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Admin brand (settings)') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $adminBrandName ?: '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Settings table') }}</dt>
                <dd class="text-gray-950 dark:text-white">
                    {{ $settingsAvailable ? tn_trans('available') : tn_trans('unavailable') }}
                </dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Settings cache') }}</dt>
                <dd class="text-gray-950 dark:text-white">
                    {{ $settingsCached ? tn_trans('warm') : tn_trans('cold') }}
                </dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('CMS name (config)') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $cmsName }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('CMS version') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $cmsVersion }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Laravel') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $laravelVersion }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('PHP') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $phpVersion }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Active theme') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $activeTheme }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Deployment mode') }}</dt>
                <dd class="text-gray-950 dark:text-white">{{ $deploymentMode }}</dd>
            </div>
            <div class="flex justify-between gap-4 sm:col-span-2">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Base path') }}</dt>
                <dd class="truncate text-gray-950 dark:text-white">{{ $basePath }}</dd>
            </div>
            <div class="flex justify-between gap-4 sm:col-span-2">
                <dt class="font-medium text-gray-500 dark:text-gray-400">{{ tn_trans('Public path') }}</dt>
                <dd class="truncate text-gray-950 dark:text-white">{{ $publicPath }}</dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
