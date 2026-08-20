<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared "Language" select for translatable resources.
 *
 * On an edit form, changing the language redirects the page to the same edit
 * URL with a `?locale=` query param. The Edit page then live-loads that
 * locale's translation in mutateFormDataBeforeFill (see EditsTranslationLocale).
 * A full re-mount is used deliberately so the TinyMCE rich editor (which lives
 * inside wire:ignore) re-initialises with the correct locale's content — a
 * plain $set() could not refresh it reliably.
 *
 * On a create form there is no record, so the select only chooses the locale
 * for the new translation (no redirect).
 */
trait HasLocaleSelect
{
    protected static function localeSelect(): Select
    {
        return Select::make('locale')
            ->label(tn_trans('Language'))
            ->options(fn (): array => app('cms.language')->optionList())
            ->default(fn (): string => static::defaultAuthoringLocale())
            ->required()
            ->live()
            ->afterStateUpdated(function (?string $state, ?Model $record, $livewire): void {
                if ($record === null || $state === null || $state === '') {
                    return;
                }

                // Switching the content editing locale (?locale) must PRESERVE
                // the current admin UI locale (?lang) so the interface language
                // does not change with it.
                $params = ['record' => $record, 'locale' => $state];
                $lang = request()->query('lang');

                if (is_string($lang) && $lang !== '') {
                    $params['lang'] = $lang;
                }

                $livewire->redirect(static::getUrl('edit', $params));
            })
            ->helperText(tn_trans('Changing language loads that translation. If it does not exist yet, the translation fields will be empty.'));
    }

    /**
     * Default authoring locale for a new translation: writing.default_language
     * when configured and active, otherwise the default language code.
     */
    protected static function defaultAuthoringLocale(): string
    {
        $language = app('cms.language');
        $preferred = settings('writing.default_language');

        if (is_string($preferred) && $preferred !== '' && $language->isActive($preferred)) {
            return $language->normalizeCode($preferred);
        }

        return $language->defaultCode();
    }

    /**
     * The locale the admin is currently VIEWING/editing translatable content in
     * — for list tables, columns and label queries so they follow the content
     * language the admin is actually working in instead of always the site
     * default.
     *
     * This is the CONTENT EDITING locale, resolved by
     * {@see \TheNguyen\CMS\Services\LocalePreferenceManager::resolveEditingLocale()}
     * (`?locale=` → session(cms.editing_locale) → default). It is deliberately
     * INDEPENDENT of the admin UI locale (`?lang` / app()->getLocale()): the
     * interface language must not move the content language.
     *
     * NOTE: deliberately NOT currentCode()/defaultCode() — currentCode() now
     * tracks the admin UI locale, and always-defaultCode() is what made the
     * taxonomy list show the default language even while editing in another
     * locale (v1.0.0-beta.7.1.9.2 fix).
     */
    protected static function viewingLocale(): string
    {
        return editing_locale();
    }
}
