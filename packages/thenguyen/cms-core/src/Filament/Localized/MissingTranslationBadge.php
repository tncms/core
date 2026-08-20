<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\Placeholder;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;
use TheNguyen\CMS\Translation\Admin\LocalizedFieldState;

/**
 * A read-only badge (Phase 8.3) listing the enabled locales that still lack a
 * completed translation for a field, by label. Shows "Complete" when none are
 * missing. Reads live form state; never persisted.
 */
final class MissingTranslationBadge
{
    public static function make(string $statePath, ?LocaleOptions $locales = null): Placeholder
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        $placeholder = Placeholder::make($statePath.'_missing')
            ->label(__('Missing translations'))
            ->content(static function ($get) use ($manager, $locales, $statePath): string {
                $state = $get($statePath);
                $fieldState = LocalizedFieldState::fromArray(is_array($state) ? $state : []);
                $missing = $manager->status->missingLocales($fieldState, $locales);

                if ($missing === []) {
                    return __('Complete');
                }

                $labels = array_map(static fn (string $code): string => $locales->labelFor($code), $missing);

                return __('Missing').': '.implode(', ', $labels);
            });

        if (method_exists($placeholder, 'dehydrated')) {
            $placeholder->dehydrated(false);
        }

        return $placeholder;
    }
}
