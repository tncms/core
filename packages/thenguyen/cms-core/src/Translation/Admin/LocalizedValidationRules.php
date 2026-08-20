<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

/**
 * Builds per-locale validation rules + messages for localized fields (Phase 8.3).
 *
 * Posture (overridable per call): the default locale is required, secondary
 * locales are optional. Supports length rules, a locale-aware slug rule, and a
 * locale-aware UNIQUE seam — a caller-supplied rule/closure is passed through
 * untouched (this phase wires no uniqueness itself). Messages always name the
 * locale's label so a failure says which language is at fault.
 */
final class LocalizedValidationRules
{
    /**
     * @param array{default_locale_required?: bool, secondary_locale_required?: bool} $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Laravel rules for one locale's input.
     *
     * $options: required_default(bool), secondary_required(bool), max(int),
     * min(int), slug(bool), unique(mixed rule — the locale-aware seam).
     *
     * @param  array<string, mixed>  $options
     * @return array<int, mixed>
     */
    public function rulesFor(string $locale, LocaleOptions $locales, array $options = []): array
    {
        $isDefault = $locales->defaultCode() === $locale;

        $requireDefault = (bool) ($options['required_default'] ?? ($this->config['default_locale_required'] ?? true));
        $requireSecondary = (bool) ($options['secondary_required'] ?? ($this->config['secondary_locale_required'] ?? false));

        $rules = [];
        $rules[] = ($isDefault && $requireDefault) || (! $isDefault && $requireSecondary)
            ? 'required'
            : 'nullable';

        if (isset($options['max'])) {
            $rules[] = 'max:'.(int) $options['max'];
        }
        if (isset($options['min'])) {
            $rules[] = 'min:'.(int) $options['min'];
        }
        if (! empty($options['slug'])) {
            $rules[] = 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/';
        }
        if (isset($options['unique'])) {
            // Locale-aware unique SEAM — passed through verbatim, not wired here.
            $rules[] = $options['unique'];
        }

        return $rules;
    }

    public function required(string $locale, LocaleOptions $locales, array $options = []): bool
    {
        return in_array('required', $this->rulesFor($locale, $locales, $options), true);
    }

    /**
     * Locale-labelled validation messages for one locale's input.
     *
     * @return array<string, string>
     */
    public function messagesFor(string $locale, LocaleOptions $locales): array
    {
        $label = $locales->labelFor($locale);

        return [
            'required' => "The :attribute ({$label}) is required.",
            'max' => "The :attribute ({$label}) is too long.",
            'min' => "The :attribute ({$label}) is too short.",
            'regex' => "The :attribute ({$label}) is not a valid slug.",
        ];
    }
}
