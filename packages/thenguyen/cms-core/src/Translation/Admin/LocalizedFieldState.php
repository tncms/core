<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Admin\Enums\LocaleValueStatus;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * The deterministic, locale-keyed state of ONE localized field (Phase 8.3).
 *
 * A plain `locale => ?string` map — no model objects — so it serializes cleanly
 * into Livewire/Filament form state. Present vs absent keys distinguish authored
 * from missing; a present null marks a deliberate clear; '' marks an authored
 * blank (see {@see LocaleValueStatus}).
 *
 * Immutable: every mutator returns a new instance.
 */
final class LocalizedFieldState
{
    /** @var array<string, string|null> */
    private readonly array $values;

    /** @param array<string, string|null> $values */
    public function __construct(array $values)
    {
        $normalized = [];
        foreach ($values as $locale => $value) {
            if ($value === null || is_string($value)) {
                $normalized[(string) $locale] = $value;
            }
        }
        $this->values = $normalized;
    }

    /** @param array<string, string|null> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public static function fromLocalizedValue(LocalizedValue $value): self
    {
        return new self($value->toArray());
    }

    public static function fromLocalizedField(LocalizedField $field): self
    {
        return self::fromLocalizedValue($field->value);
    }

    public function has(string $locale): bool
    {
        return array_key_exists($locale, $this->values);
    }

    public function get(string $locale): ?string
    {
        return $this->values[$locale] ?? null;
    }

    public function status(string $locale): LocaleValueStatus
    {
        if (! array_key_exists($locale, $this->values)) {
            return LocaleValueStatus::Missing;
        }

        $value = $this->values[$locale];

        return match (true) {
            $value === null => LocaleValueStatus::Null,
            $value === '' => LocaleValueStatus::Empty,
            default => LocaleValueStatus::Filled,
        };
    }

    /** @return array<int, string> locales carrying a non-empty value */
    public function filledLocales(): array
    {
        $filled = [];
        foreach ($this->values as $locale => $value) {
            if (is_string($value) && $value !== '') {
                $filled[] = $locale;
            }
        }

        return $filled;
    }

    public function withLocale(string $locale, ?string $value): self
    {
        $values = $this->values;
        $values[$locale] = $value;

        return new self($values);
    }

    public function withoutLocale(string $locale): self
    {
        $values = $this->values;
        unset($values[$locale]);

        return new self($values);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return $this->values;
    }
}
