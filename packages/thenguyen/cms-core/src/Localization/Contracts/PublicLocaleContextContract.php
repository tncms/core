<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Contracts;

/**
 * CORE-L10N.1B — the single, read-only view of the current request's locales.
 *
 * It resolves ONE public-content locale for the whole request, so Product, Category, Brand
 * and every other resource render in the same locale (fixing the audit's cross-resource
 * divergence). It also exposes — but keeps separate — the admin-UI locale and the
 * content-editing locale, all sourced from the existing Core authorities
 * ({@see \TheNguyen\CMS\Services\LanguageManager} + {@see \TheNguyen\CMS\Services\LocalePreferenceManager}).
 *
 * Locale ownership belongs exclusively to Core: this contract is READ-ONLY for plugins.
 * There is deliberately no setter/persist/replace method — plugins consume the locale, they
 * never change, persist, or mutate it.
 */
interface PublicLocaleContextContract
{
    /** The public-content locale for the current request. */
    public function current(): string;

    /** The platform default locale. */
    public function default(): string;

    /** Whether $code is an enabled public locale. */
    public function isPublic(string $code): bool;

    /** The admin-interface locale (independent of the public and editing locales). */
    public function adminLocale(): string;

    /** The content-editing/translation locale (independent of the public and admin locales). */
    public function editingLocale(): string;
}
