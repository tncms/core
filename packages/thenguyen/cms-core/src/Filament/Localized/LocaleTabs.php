<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Localized;

use Closure;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;
use TheNguyen\CMS\Translation\Admin\Support\LocalizedAdminEvents;

/**
 * The building block for every localized field component (Phase 8.3): a Filament
 * Tabs with one tab per enabled locale, each holding the field bound to that
 * locale's state path (`{statePath}.{locale}`). Default locale tab is marked.
 *
 * Locale count is arbitrary — the tabs come straight from the locale registry
 * projection. When the count exceeds the configured overflow threshold, the tabs
 * switch to a non-contained (scrollable) presentation. Optional styling methods
 * are guarded so the wrapper stays resilient across Filament point releases.
 */
final class LocaleTabs
{
    /**
     * @param  Closure(string $statePath, LocaleOption $locale, LocaleOptions $locales): mixed  $fieldFactory
     */
    public static function make(string $statePath, Closure $fieldFactory, ?LocaleOptions $locales = null, ?string $label = null): Tabs
    {
        $manager = app('cms.translation.admin');
        $locales ??= $manager->locales();

        LocalizedAdminEvents::fire(LocalizedAdminEvents::BUILDING, $statePath, $locales);

        $tabs = [];
        foreach ($locales as $option) {
            $tab = Tab::make($option->label)->schema([
                $fieldFactory("{$statePath}.{$option->code}", $option, $locales),
            ]);

            if ($option->isDefault && method_exists($tab, 'badge')) {
                $tab->badge(__('Default'));
            }

            $tabs[] = $tab;
        }

        $component = Tabs::make($label ?? $statePath)->tabs($tabs);

        if ($locales->isOverflow($manager->overflowThreshold()) && method_exists($component, 'contained')) {
            $component->contained(false);
        }

        LocalizedAdminEvents::fire(LocalizedAdminEvents::BUILT, $statePath, $locales);

        return $component;
    }

    /**
     * The per-locale state paths for a component — the guarantee of editor
     * isolation (each locale edits an independent key).
     *
     * @return array<string, string> locale => "{statePath}.{locale}"
     */
    public static function stateKeys(string $statePath, LocaleOptions $locales): array
    {
        $keys = [];
        foreach ($locales->codes() as $code) {
            $keys[$code] = "{$statePath}.{$code}";
        }

        return $keys;
    }
}
