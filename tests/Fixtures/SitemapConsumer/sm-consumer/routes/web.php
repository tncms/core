<?php

use Illuminate\Support\Facades\Route;
use Plugins\SmConsumer\Families;
use Plugins\SmConsumer\PublicController;

// Loaded by ExtensionManager inside ['web', 'cms.plugin-active:sm-consumer'].
// Default locale unprefixed, other public locales under /{locale}.
$languages = app('cms.language');
$default = $languages->defaultCode();

foreach ($languages->getPublicLocales() ?: [$default] as $locale) {
    $prefixed = $locale !== $default || $languages->shouldPrefixDefaultLocale();
    $group = $prefixed ? Route::prefix('{locale}')->whereIn('locale', [$locale]) : Route::middleware([]);

    $group->group(static function () use ($locale): void {
        foreach (Families::TYPES as $type => $family) {
            $segment = $family['segments'][$locale] ?? null;

            if ($segment === null) {
                continue;
            }

            $uri = ($family['lottery'] ?? false) ? $segment.'/{region}/{date}.html' : $segment.'/{slug}';

            Route::get($uri, [PublicController::class, 'show'])
                ->defaults('smc_type', $type)
                ->defaults('smc_locale', $locale)
                ->name('smc.'.$type.'.'.$locale);
        }
    });
}
