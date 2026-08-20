<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Filament\Concerns;

use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use TheNguyen\CMS\Contracts\TranslatablePluginContent;

/**
 * Unified translation helpers for Filament resources (Phase 1D).
 *
 * The single translation brain for the whole CMS — Pages, Posts, taxonomy terms,
 * menus, and any plugin resource. It resolves the current/default/available
 * locales, whether a translation exists, and the edit URL for a locale, and it
 * supplies the create-only language form field. {@see TranslationSwitcher} renders
 * the header control on top of these methods.
 *
 * Two data shapes are supported transparently, branching only on the core
 * {@see TranslatablePluginContent} contract (never on a concrete model or plugin
 * namespace):
 *
 * - Parent + child (core Content/Term/Menu): one record, many child translation
 *   rows. Locale is carried in the `?locale=` query; a missing translation is
 *   created by filling and saving the form for that locale.
 * - Linked records (plugins via HasPluginTranslations): one record per locale,
 *   siblings linked by a translation group. A missing translation is created as a
 *   new sibling record.
 *
 * Resources use translationLocaleField() in their form; edit pages also use the
 * TranslationSwitcher trait and add translationSwitcher() to the header actions.
 */
trait HasTranslations
{
    /**
     * Create-only "Language" select for the resource form. On edit the locale is
     * fixed and switching is handled by the header TranslationSwitcher, so the
     * field is hidden there (no raw locale input during normal editing).
     */
    protected static function translationLocaleField(): Select
    {
        return Select::make('locale')
            ->label(tn_trans('Language'))
            ->options(fn (): array => app('cms.language')->optionList())
            ->default(fn (): string => static::defaultTranslationLocale())
            ->required()
            ->hiddenOn('edit')
            ->helperText(tn_trans('The language this content belongs to.'));
    }

    /**
     * Localised, flag-prefixed list column for a record's locale (Phase 1E).
     *
     * A translated row always renders its language ("🇻🇳 Tiếng Việt"); only a
     * genuinely locale-neutral (null) row shows "Neutral". Reused by every
     * resource so the admin list never exposes a bare/empty locale cell.
     */
    protected static function translationLocaleColumn(): TextColumn
    {
        return TextColumn::make('locale')
            ->label(tn_trans('Language'))
            ->badge()
            ->color(fn (?string $state): string => blank($state) ? 'gray' : 'primary')
            ->formatStateUsing(function (?string $state): string {
                if (blank($state)) {
                    return tn_trans('Neutral');
                }

                $language = app('cms.language')->find($state);
                $name = $language?->displayName() ?? $state;
                $flag = $language?->flag;

                return trim(($flag ? $flag.' ' : '').$name);
            });
    }

    /**
     * Default authoring locale: writing.default_language when configured and
     * active, otherwise the site default language code.
     */
    protected static function defaultTranslationLocale(): string
    {
        $language = app('cms.language');
        $preferred = settings('writing.default_language');

        if (is_string($preferred) && $preferred !== '' && $language->isActive($preferred)) {
            return $language->normalizeCode($preferred);
        }

        return $language->defaultCode();
    }

    /**
     * The locale being edited, pinned into component state for the request
     * lifecycle.
     *
     * Resolved once from the `?locale=` query on the initial page load and then
     * held here. Livewire posts the Save commit to its own endpoint WITHOUT the
     * page query string, so re-reading request()->query('locale') at save time
     * would wrongly fall back to the default locale and overwrite the wrong
     * translation row (e.g. saving an `en` edit would clobber the `vi` row).
     * Memoising the resolved value keeps fill, render, and save pinned to the
     * same locale across the stateless commit.
     */
    public ?string $translationLocale = null;

    /**
     * The locale requested via the `?locale=` query (validated against active
     * languages), falling back to the default. Resolved once and memoised into
     * component state so it survives the query-less Livewire save commit. Drives
     * the parent+child edit flow.
     */
    protected function selectedLocale(): string
    {
        if (is_string($this->translationLocale) && $this->translationLocale !== '') {
            return $this->translationLocale;
        }

        return $this->translationLocale = $this->resolveRequestedLocale();
    }

    /**
     * Resolve the CONTENT EDITING locale (`?locale=` → session(cms.editing_locale)
     * → default) via the locale preference manager. Independent of the admin UI
     * locale (`?lang`). Only meaningful on the initial page load —
     * {@see selectedLocale()} caches the result thereafter.
     */
    protected function resolveRequestedLocale(): string
    {
        return editing_locale();
    }

    /**
     * The locale the switcher treats as "current" for the record being edited.
     */
    protected function currentTranslationLocale(): string
    {
        $record = $this->translationRecord();

        if ($record instanceof TranslatablePluginContent) {
            return $record->getLocale() ?? app('cms.language')->defaultCode();
        }

        return $this->selectedLocale();
    }

    protected function translationExistsFor(string $locale): bool
    {
        $record = $this->translationRecord();

        return $record !== null && method_exists($record, 'hasTranslation')
            ? (bool) $record->hasTranslation($locale)
            : false;
    }

    /**
     * Resolve the edit URL for $locale, creating the translation when necessary.
     * May have side effects (linked-record plugins insert a sibling), so call it
     * only at click time — never while rendering the switcher.
     */
    protected function translationUrlFor(string $locale): string
    {
        $record = $this->translationRecord();
        $resource = static::getResource();

        // Switching the content editing locale (?locale) must PRESERVE the
        // current admin UI locale (?lang) so the interface language is unchanged.
        $lang = request()->query('lang');
        $langParam = (is_string($lang) && $lang !== '') ? ['lang' => $lang] : [];

        if ($record instanceof TranslatablePluginContent) {
            $target = $record->translationFor($locale) ?? $record->createTranslationFor($locale);

            return $resource::getUrl('edit', ['record' => $target] + $langParam);
        }

        // Parent+child: stay on the same record and switch the ?locale= param.
        return $resource::getUrl('edit', ['record' => $record, 'locale' => $locale] + $langParam);
    }

    /**
     * Keep the editor on the just-saved translation after a save.
     */
    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();
        $resource = static::getResource();

        if ($record instanceof TranslatablePluginContent) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        $locale = $this->data['locale'] ?? $this->selectedLocale();

        return $resource::getUrl('edit', ['record' => $record, 'locale' => $locale]);
    }

    /**
     * The record under edit, or null on a create page (no switcher there).
     */
    protected function translationRecord(): ?object
    {
        return method_exists($this, 'getRecord') && $this->getRecord()?->exists
            ? $this->getRecord()
            : null;
    }
}
