<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Tabs;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A per-locale rich text editor (Phase 8.3): locale tabs of {@see RichEditor}s.
 *
 * The editors sit inside locale tabs, which Filament renders on demand, so only
 * the active locale's heavy editor is mounted — the others load lazily as tabs
 * are opened.
 */
final class LocalizedRichEditor
{
    /** @param array<string, mixed> $options */
    public static function make(string $statePath, array $options = [], ?LocaleOptions $locales = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return LocaleTabs::make(
            $statePath,
            static fn (string $path, LocaleOption $opt): RichEditor => RichEditor::make($path)
                ->label($options['label'] ?? null)
                ->rules($manager->validation->rulesFor($opt->code, $locales, $options))
                ->dehydrated(true),
            $locales,
            $options['label'] ?? null,
        );
    }
}
