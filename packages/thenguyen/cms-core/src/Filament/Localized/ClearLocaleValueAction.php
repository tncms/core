<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Actions\Action;

/**
 * An action (Phase 8.3) that clears one locale's value for a field (a deliberate
 * clear the hydrator removes on save). Delegates to the pure
 * {@see \TheNguyen\CMS\Translation\Admin\Actions\ClearLocaleValue} operation.
 */
final class ClearLocaleValueAction
{
    public static function make(string $statePath, string $locale): Action
    {
        $manager = app('cms.translation.admin');

        return Action::make('clearLocale_'.$statePath.'_'.$locale)
            ->label(__('Clear'))
            ->requiresConfirmation()
            ->action(function ($get, $set) use ($manager, $statePath, $locale): void {
                $state = $get($statePath);
                $state = is_array($state) ? $state : [];

                $set($statePath, $manager->clear->apply($state, $locale));
            });
    }
}
