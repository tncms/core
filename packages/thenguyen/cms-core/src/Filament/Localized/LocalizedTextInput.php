<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A per-locale text input (Phase 8.3): locale tabs of {@see TextInput}s, each
 * validated with the locale-aware rules (default required, secondary optional).
 *
 * $options: label, max, min, required_default, secondary_required, unique (the
 * locale-aware unique seam).
 */
final class LocalizedTextInput
{
    /** @param array<string, mixed> $options */
    public static function make(string $statePath, array $options = [], ?LocaleOptions $locales = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return LocaleTabs::make(
            $statePath,
            static fn (string $path, LocaleOption $opt): TextInput => TextInput::make($path)
                ->label($options['label'] ?? null)
                ->rules($manager->validation->rulesFor($opt->code, $locales, $options))
                ->dehydrated(true),
            $locales,
            $options['label'] ?? null,
        );
    }
}
