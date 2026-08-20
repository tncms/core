<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\Select;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;
use TheNguyen\CMS\Translation\Admin\Support\LocalizedAdminEvents;

/**
 * A locale selector (Phase 8.3) for single-locale-at-a-time UIs, populated from
 * the enabled locales (default preselected). It is a UI control only — not
 * persisted — and fires the locale-switched event on change.
 */
final class LocaleSwitcher
{
    public static function make(string $statePath = 'admin_locale', ?LocaleOptions $locales = null): Select
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return Select::make($statePath)
            ->label(__('Language'))
            ->options($locales->labels())
            ->default($locales->defaultCode())
            ->live()
            ->afterStateUpdated(static function ($state): void {
                LocalizedAdminEvents::fire(LocalizedAdminEvents::LOCALE_SWITCHED, $state);
            })
            ->dehydrated(false);
    }
}
