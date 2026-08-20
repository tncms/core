<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Actions\Action;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * An action (Phase 8.3) that copies the default-locale value of a field into a
 * target locale, with an overwrite confirmation. Delegates to the pure
 * {@see \TheNguyen\CMS\Translation\Admin\Actions\CopyFromDefaultLocale} operation.
 */
final class CopyFromDefaultLocaleAction
{
    public static function make(string $statePath, string $targetLocale, ?LocaleOptions $locales = null): Action
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return Action::make('copyFromDefault_'.$statePath.'_'.$targetLocale)
            ->label(__('Copy from default'))
            ->requiresConfirmation()
            ->action(function ($get, $set) use ($manager, $locales, $statePath, $targetLocale): void {
                $state = $get($statePath);
                $state = is_array($state) ? $state : [];

                // The confirmation modal is the overwrite guard, so apply with
                // overwrite: true once the user confirms.
                $set($statePath, $manager->copy->apply($state, $locales, $targetLocale, overwrite: true));
            });
    }
}
