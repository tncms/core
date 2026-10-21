<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Profile;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Normalizes and validates a raw profile value against its field definition
 * (Account Profile Field Engine, P1-B). Validation is declarative (Laravel
 * rules compiled from the field's structured options) — never arbitrary PHP.
 * A value that fails validation raises {@see ValidationException}; the caller
 * never persists an unvalidated value.
 *
 * Supported types: text, textarea, number (int|float), boolean, date,
 * datetime, url, email, select, radio, checkbox (multi), media (id reference).
 * Empty string and null both normalize to null (an explicit "unset"), which is
 * kept distinct from the typed values 0 / false.
 */
class ProfileValueCaster
{
    /** URL schemes permitted unless the field narrows them via options.schemes. */
    private const DEFAULT_URL_SCHEMES = ['https', 'http'];

    private const TEXT_MAX = 255;

    private const TEXTAREA_MAX = 5000;

    /**
     * Validate then normalize. Returns null for an explicit empty value.
     *
     * @throws ValidationException
     */
    public function cast(ProfileFieldDefinition $def, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        Validator::make(['value' => $value], ['value' => $this->rules($def)])->validate();

        // Type-specific guards Laravel's rule set does not express directly.
        if ($def->type === 'url') {
            $this->assertAllowedUrlScheme($def, (string) $value);
        }

        if (in_array($def->type, ['select', 'radio'], true)) {
            $this->assertInChoices($def, [(string) $value]);
        }

        if ($def->type === 'checkbox') {
            $this->assertInChoices($def, array_map('strval', (array) $value));
        }

        return $this->normalize($def, $value);
    }

    /**
     * Lowercased scalar projection for indexed filtering, or null when the value
     * is multi-valued or must not be indexed.
     */
    public function indexValue(ProfileFieldDefinition $def, mixed $normalized): ?string
    {
        if ($normalized === null || is_array($normalized)) {
            return null;
        }

        $scalar = is_bool($normalized) ? ($normalized ? '1' : '0') : (string) $normalized;

        return mb_substr(mb_strtolower($scalar), 0, 191);
    }

    /** @return array<int, mixed> */
    private function rules(ProfileFieldDefinition $def): array
    {
        $o = $def->options;

        return match ($def->type) {
            'text' => ['string', 'max:'.(int) ($o['maxlength'] ?? self::TEXT_MAX)],
            'textarea' => ['string', 'max:'.(int) ($o['maxlength'] ?? self::TEXTAREA_MAX)],
            'number' => array_filter([
                'numeric',
                isset($o['min']) ? 'min:'.$o['min'] : null,
                isset($o['max']) ? 'max:'.$o['max'] : null,
            ]),
            'boolean' => ['boolean'],
            'date' => ['date_format:Y-m-d'],
            'datetime' => ['date'],
            'url' => ['string', 'url', 'max:2048'],
            'email' => ['string', 'email:rfc', 'max:255'],
            'select', 'radio' => ['string'],
            'checkbox' => ['array'],
            'media' => ['integer', 'min:1'],
            default => ['prohibited'], // unknown type can never validate
        };
    }

    private function normalize(ProfileFieldDefinition $def, mixed $value): mixed
    {
        return match ($def->type) {
            'text', 'url', 'email', 'select', 'radio' => trim((string) $value),
            'textarea' => (string) $value,
            'number' => $this->normalizeNumber($value),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date', 'datetime' => (string) $value,
            'checkbox' => array_values(array_unique(array_map('strval', (array) $value))),
            'media' => (int) $value,
            default => $value,
        };
    }

    private function normalizeNumber(mixed $value): int|float
    {
        $n = $value + 0; // numeric-validated already

        return (is_int($n) || (float) (int) $n === (float) $n) ? (int) $n : (float) $n;
    }

    /**
     * @param  array<int, string>  $values
     *
     * @throws ValidationException
     */
    private function assertInChoices(ProfileFieldDefinition $def, array $values): void
    {
        $choices = array_map('strval', array_keys($this->choiceMap($def)));

        foreach ($values as $v) {
            if (! in_array($v, $choices, true)) {
                throw ValidationException::withMessages(['value' => "The selected value \"{$v}\" is invalid."]);
            }
        }
    }

    /** @return array<string, mixed> choices as value => label (labels unused here). */
    private function choiceMap(ProfileFieldDefinition $def): array
    {
        $choices = $def->options['choices'] ?? [];

        if (! is_array($choices)) {
            return [];
        }

        // Accept both ['a','b'] and ['a' => 'Label A'] shapes.
        return array_is_list($choices) ? array_fill_keys($choices, null) : $choices;
    }

    /** @throws ValidationException */
    private function assertAllowedUrlScheme(ProfileFieldDefinition $def, string $url): void
    {
        $allowed = $def->options['schemes'] ?? self::DEFAULT_URL_SCHEMES;
        $allowed = array_map('strtolower', (array) $allowed);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '' || ! in_array($scheme, $allowed, true)) {
            throw ValidationException::withMessages([
                'value' => 'The URL scheme is not allowed.',
            ]);
        }
    }
}
