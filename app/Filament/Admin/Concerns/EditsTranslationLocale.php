<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

/**
 * Edit-page support for the live translation locale switcher.
 *
 * - selectedLocale() resolves the CONTENT EDITING locale from the ?locale=
 *   query param (validated against active languages), falling back to the
 *   session editing locale, then the default. This is independent of the admin
 *   UI locale (?lang); see {@see \TheNguyen\CMS\Services\LocalePreferenceManager}.
 * - getRedirectUrl() keeps the user on the same edit page in the same locale
 *   after saving, so the just-saved translation is shown.
 */
trait EditsTranslationLocale
{
    protected function selectedLocale(): string
    {
        return editing_locale();
    }

    protected function getRedirectUrl(): string
    {
        $locale = $this->data['locale'] ?? $this->selectedLocale();

        return static::getResource()::getUrl('edit', [
            'record' => $this->getRecord(),
            'locale' => $locale,
        ]);
    }
}
