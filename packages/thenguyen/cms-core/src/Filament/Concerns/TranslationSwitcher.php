<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;

/**
 * Unified translation language switcher for Filament edit pages (Phase 1D).
 *
 * Renders one header control listing every active language: the current language
 * is marked and disabled, an existing translation opens directly, and a missing
 * translation is created and opened. The same control is used by Pages, Posts,
 * taxonomy terms, menus, and any plugin resource — there is no second switcher.
 *
 * Requires the {@see HasTranslations} trait, which supplies the locale resolution
 * (current locale, per-locale existence, and the per-locale edit URL). This trait
 * is pure presentation and knows nothing about concrete models or plugins.
 */
trait TranslationSwitcher
{
    protected function translationSwitcher(): ActionGroup
    {
        $current = $this->currentTranslationLocale();

        $actions = app('cms.language')->active()
            ->map(fn ($language): Action => $this->translationSwitcherAction($language, $current))
            ->all();

        return ActionGroup::make($actions)
            ->label($this->translationSwitcherLabel($current))
            ->icon('heroicon-o-language')
            ->button();
    }

    /**
     * @param  \TheNguyen\CMS\Models\Language  $language
     */
    protected function translationSwitcherAction($language, string $current): Action
    {
        $code = $language->code;
        $isCurrent = $code === $current;
        $exists = $isCurrent || $this->translationExistsFor($code);

        return Action::make('translate_to_'.$code)
            ->label($language->displayName())
            ->icon($isCurrent
                ? 'heroicon-o-check-circle'
                : ($exists ? 'heroicon-o-language' : 'heroicon-o-plus-circle'))
            ->disabled($isCurrent)
            ->action(function () use ($code, $isCurrent): void {
                if ($isCurrent) {
                    return;
                }

                // Resolve (and, when missing, create) the target only on click.
                $this->redirect($this->translationUrlFor($code));
            });
    }

    protected function translationSwitcherLabel(string $current): string
    {
        $name = app('cms.language')->find($current)?->displayName() ?? $current;

        return tn_trans('Language').': '.$name;
    }
}
