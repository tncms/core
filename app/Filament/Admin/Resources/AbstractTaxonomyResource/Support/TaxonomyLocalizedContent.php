<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AbstractTaxonomyResource\Support;

use Closure;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields;
use TheNguyen\CMS\Translation\Admin\FallbackPreviewResult;
use TheNguyen\CMS\Translation\Admin\LocaleOption;
use TheNguyen\CMS\Translation\Admin\LocaleOptions;
use TheNguyen\CMS\Translation\Admin\LocalizedAdminManager;
use TheNguyen\CMS\Translation\Content\LocalizedFieldDefinition;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * Phase 9.2F — Taxonomy admin adoption of the Translation Platform (Phase 8.3/8.4).
 *
 * The taxonomy analog of
 * {@see \App\Filament\Admin\Resources\PageResource\Support\PageLocalizedContent}:
 * the Filament-free seam between the shared taxonomy admin editor
 * ({@see \App\Filament\Admin\Resources\AbstractTaxonomyResource}) and the
 * Translation Platform. It NEVER writes — it only sources locale ordering,
 * validation rules/messages, slug previews, and fallback previews. All writes stay
 * with TaxonomyManager (EditTerm/CreateTerm pages → TaxonomyManager →
 * TaxonomyTranslationWriteAdapter → TermTranslationDriver, Phase 9.2E).
 *
 * ONE SEAM, EVERY TAXONOMY. It keys everything on the canonical taxonomy field
 * vocabulary ({@see TaxonomyTranslationFields}) and the term's stored translations,
 * so Category, Tag, Brand, Genre, Knowledge Category, Product Category and every
 * plugin taxonomy reuse it with NO concrete-taxonomy branching (no
 * `if type === 'category'`). Hierarchy, parent, ordering, count, visibility and
 * permissions remain owned by the resource/TaxonomyManager and are untouched here.
 *
 * The taxonomy editor keeps its single-locale-per-term model, so these helpers are
 * scalar (one locale at a time) rather than the all-locale nested state the Phase
 * 8.3 LocaleTabs wrappers expect.
 */
final class TaxonomyLocalizedContent
{
    /**
     * The localized taxonomy fields the editor adopts, keyed by the admin form
     * field name (which matches the cms_term_translations column). This is the
     * cms-core canonical vocabulary (name/slug/description/meta_title/
     * meta_description) — the single source of truth shared with the 9.2C driver
     * and 9.2D/E adapters. Terms carry NO excerpt/content split and NO
     * meta_keywords, unlike Content.
     *
     * @return array<string, LocalizedFieldDefinition>
     */
    public static function definitions(): array
    {
        return TaxonomyTranslationFields::definitions();
    }

    public static function definition(string $field): ?LocalizedFieldDefinition
    {
        return self::definitions()[$field] ?? null;
    }

    /** @return array<int, string> */
    public static function fieldNames(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Active site locales as a Translation Platform LocaleOptions, default-first.
     *
     * IMPORTANT: the locale SET and default are sourced from the authoritative
     * LanguageManager (cms.language / cms_languages), NOT from the LocaleRegistry.
     * The registry is seeded once at boot from config('translation.locales')
     * (empty by default) and is NOT synced with the languages an admin enables in
     * the UI. Sourcing from the registry would drop active languages from the
     * editor and compute the wrong "default locale" for validation. We still feed
     * this LocaleOptions to the platform components, so they are adopted without
     * depending on the unsynced registry. No hardcoded locales.
     */
    public static function locales(): LocaleOptions
    {
        $language = app('cms.language');

        $default = (string) $language->defaultCode();
        $fallback = (string) config('translation.fallback_locale', $default);

        /** @var array<string, string> $labels */
        $labels = $language->optionList();
        $codes = array_map('strval', array_keys($labels));

        // Default locale first, remaining active locales in their existing order.
        if ($default !== '' && in_array($default, $codes, true)) {
            $codes = array_merge(
                [$default],
                array_values(array_filter($codes, static fn (string $code): bool => $code !== $default)),
            );
        }

        $options = [];
        foreach ($codes as $code) {
            $options[] = new LocaleOption(
                $code,
                (string) ($labels[$code] ?? $code),
                null,
                $code === $default,
                $code === $fallback,
            );
        }

        return new LocaleOptions($options);
    }

    /**
     * Locale options for a Filament Select: [code => label], default-first.
     *
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        $options = [];
        foreach (self::locales() as $option) {
            $options[$option->code] = $option->label;
        }

        return $options;
    }

    public static function defaultLocale(): ?string
    {
        return self::locales()->defaultCode();
    }

    /**
     * Whether the field is required for the given locale (default-locale required,
     * secondary optional — per the field's validation policy). Only `name` is
     * required, and only in the default locale.
     */
    public static function isRequired(string $field, string $locale): bool
    {
        $definition = self::definition($field);

        if ($definition === null) {
            return false;
        }

        return self::manager()->validation->required(
            $locale,
            self::locales(),
            $definition->validationPolicy()->toOptions(),
        );
    }

    /**
     * A single closure validation rule that enforces the platform's locale-aware
     * rules for a field and emits locale-aware messages. Returned as a Laravel
     * closure rule so Filament can evaluate it per request with the live locale.
     */
    public static function validationRule(string $field, string $locale): Closure
    {
        $rules = self::rulesFor($field, $locale);
        $messages = self::manager()->validation->messagesFor($locale, self::locales());

        return static function (string $attribute, mixed $value, Closure $fail) use ($rules, $messages): void {
            $string = is_string($value) ? $value : ($value === null ? '' : (string) $value);

            foreach ($rules as $rule) {
                if ($rule === 'required') {
                    if (trim($string) === '') {
                        $fail($messages['required'] ?? 'The :attribute is required.');

                        return;
                    }

                    continue;
                }

                if ($rule === 'nullable') {
                    continue;
                }

                if (is_string($rule) && str_starts_with($rule, 'max:')) {
                    if (mb_strlen($string) > (int) substr($rule, 4)) {
                        $fail($messages['max'] ?? 'The :attribute is too long.');

                        return;
                    }

                    continue;
                }

                if (is_string($rule) && str_starts_with($rule, 'min:')) {
                    if ($string !== '' && mb_strlen($string) < (int) substr($rule, 4)) {
                        $fail($messages['min'] ?? 'The :attribute is too short.');

                        return;
                    }

                    continue;
                }

                if (is_string($rule) && str_starts_with($rule, 'regex:')) {
                    $pattern = substr($rule, 6);
                    if ($string !== '' && @preg_match($pattern, $string) !== 1) {
                        $fail($messages['regex'] ?? 'The :attribute is not valid.');

                        return;
                    }
                }
            }
        };
    }

    /**
     * The platform rule list for a field/locale, e.g. ['required', 'max:255'] or
     * ['nullable', 'regex:...', 'max:255'].
     *
     * @return array<int, string>
     */
    public static function rulesFor(string $field, string $locale): array
    {
        $definition = self::definition($field);

        if ($definition === null) {
            return [];
        }

        return self::manager()->validation->rulesFor(
            $locale,
            self::locales(),
            $definition->validationPolicy()->toOptions(),
        );
    }

    /**
     * A locale-aware slug generated from the given name, for preview only.
     * Delegates to the platform SlugManager; performs NO cms_slugs write or
     * reservation. Returns null when there is no usable name. SlugManager remains
     * the ONLY slug authority — this is a display placeholder.
     */
    public static function generateSlug(?string $name, string $locale): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $slug = self::manager()->slugs->generate($name, $locale);

        return $slug === '' ? null : $slug;
    }

    /**
     * Read-only fallback preview for a field in the requested locale, built from
     * the term's stored translations and the live (unsaved) value for the current
     * locale. Never persisted; used only to show editors what the frontend would
     * inherit when the current locale is empty.
     *
     * @param  string|null  $currentValue  the live form value for $locale (form takes precedence over the stored row)
     */
    public static function fallbackPreview(Term $record, string $field, string $locale, ?string $currentValue = null): FallbackPreviewResult
    {
        $values = [];

        foreach ($record->translations as $translation) {
            $stored = $translation->{$field} ?? null;
            if (is_string($stored) && $stored !== '') {
                $values[$translation->locale] = $stored;
            }
        }

        // The live form value wins over the stored row for the edited locale:
        // a value being typed suppresses the fallback; a cleared value reveals it.
        if (is_string($currentValue) && trim($currentValue) !== '') {
            $values[$locale] = $currentValue;
        } else {
            unset($values[$locale]);
        }

        return self::manager()->fallback->preview(LocalizedField::make($values), $locale);
    }

    private static function manager(): LocalizedAdminManager
    {
        /** @var LocalizedAdminManager */
        return app('cms.translation.admin');
    }
}
