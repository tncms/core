<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\Placeholder;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;
use TheNguyen\CMS\Translation\Admin\LocalizedFieldState;

/**
 * A read-only completion indicator (Phase 8.3): shows how many enabled locales of
 * a field are completed (e.g. "2 / 5"). Reads live form state; never persisted.
 */
final class TranslationStatusIndicator
{
    public static function make(string $statePath, ?LocaleOptions $locales = null): Placeholder
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        $placeholder = Placeholder::make($statePath.'_status')
            ->label(__('Translation status'))
            ->content(static function ($get) use ($manager, $locales, $statePath): string {
                $state = $get($statePath);
                $fieldState = LocalizedFieldState::fromArray(is_array($state) ? $state : []);
                $completed = count($manager->status->completedLocales($fieldState, $locales));

                return $completed.' / '.$locales->count();
            });

        if (method_exists($placeholder, 'dehydrated')) {
            $placeholder->dehydrated(false);
        }

        return $placeholder;
    }
}
