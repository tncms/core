<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Filament\Forms\Components\KeyValue;
use Filament\Schemas\Components\Tabs;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;

/**
 * A per-locale key/value editor (Phase 8.3): locale tabs of {@see KeyValue}s, for
 * localized maps (e.g. per-locale attribute or meta pairs). Each locale keeps its
 * own independent map.
 */
final class LocalizedKeyValue
{
    /** @param array<string, mixed> $options */
    public static function make(string $statePath, array $options = [], ?LocaleOptions $locales = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        return LocaleTabs::make(
            $statePath,
            static fn (string $path, LocaleOption $opt): KeyValue => KeyValue::make($path)
                ->label($options['label'] ?? null)
                ->dehydrated(true),
            $locales,
            $options['label'] ?? null,
        );
    }
}
