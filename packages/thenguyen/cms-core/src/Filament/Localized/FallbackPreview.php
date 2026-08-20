<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\Placeholder;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A read-only preview (Phase 8.3) of what the frontend would show for a locale,
 * using the engine's fallback chain — so an editor can see the inherited value
 * for an untranslated locale. Purely informational: it is a Placeholder and is
 * explicitly never dehydrated/persisted.
 */
final class FallbackPreview
{
    public static function make(string $statePath, string $locale, ?LocaleOptions $locales = null): Placeholder
    {
        $manager = app('cms.translation.admin');

        $placeholder = Placeholder::make($statePath.'_fallback_'.$locale)
            ->label(__('Fallback preview'))
            ->content(static function ($get) use ($manager, $statePath, $locale): string {
                $state = $get($statePath);
                $preview = $manager->fallback->previewState(is_array($state) ? $state : [], $locale);

                if (! $preview->found) {
                    return __('No value');
                }

                $suffix = $preview->isFallback && $preview->sourceLocale !== null
                    ? ' ('.$preview->sourceLocale.')'
                    : '';

                return (string) $preview->value.$suffix;
            });

        // Explicitly non-persisted (Placeholders are display-only anyway).
        if (method_exists($placeholder, 'dehydrated')) {
            $placeholder->dehydrated(false);
        }

        return $placeholder;
    }
}
