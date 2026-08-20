<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Tabs;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A per-locale multi-line text input (Phase 8.3): locale tabs of {@see Textarea}s
 * with locale-aware validation.
 */
final class LocalizedTextarea
{
    /** @param array<string, mixed> $options */
    public static function make(string $statePath, array $options = [], ?LocaleOptions $locales = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return LocaleTabs::make(
            $statePath,
            static fn (string $path, LocaleOption $opt): Textarea => Textarea::make($path)
                ->label($options['label'] ?? null)
                ->rules($manager->validation->rulesFor($opt->code, $locales, $options))
                ->dehydrated(true),
            $locales,
            $options['label'] ?? null,
        );
    }
}
