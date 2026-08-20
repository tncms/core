<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * Immutable, multi-locale value object: a `locale => string` map plus an
 * optional `raw` (the untranslated source, e.g. a plain non-localized column).
 *
 * This is the engine-level DTO consumed by the resolver and {@see LocalizedField}.
 * It is intentionally distinct from the low-level {@see \TheNguyen\CMS\Support\LocalizedValue}
 * array helper, which remains the leaf primitive for raw locale maps; this DTO
 * wraps that concept in an immutable object with an explicit raw fallback.
 *
 * Mutation always returns a new instance — the original is never changed.
 */
final class LocalizedValue
{
    /** @var array<string, string> */
    public readonly array $values;

    /**
     * @param array<string, string|null> $values locale => value (nulls dropped, values cast to string)
     */
    public function __construct(array $values = [], public readonly ?string $raw = null)
    {
        $normalized = [];
        foreach ($values as $locale => $value) {
            if ($value === null) {
                continue;
            }
            $normalized[(string) $locale] = (string) $value;
        }
        $this->values = $normalized;
    }

    /**
     * @param array<string, string|null> $values
     */
    public static function make(array $values = [], ?string $raw = null): self
    {
        return new self($values, $raw);
    }

    /** A value with no localized entries, carrying only the raw source. */
    public static function fromRaw(?string $raw): self
    {
        return new self([], $raw);
    }

    public function has(string $locale): bool
    {
        return array_key_exists($locale, $this->values) && $this->values[$locale] !== '';
    }

    /** Exact lookup for one locale (no fallback); null when absent/empty. */
    public function get(string $locale): ?string
    {
        return $this->has($locale) ? $this->values[$locale] : null;
    }

    /** @return array<int, string> */
    public function locales(): array
    {
        return array_keys($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [] && ($this->raw === null || $this->raw === '');
    }

    public function withValue(string $locale, ?string $value): self
    {
        $values = $this->values;
        if ($value === null) {
            unset($values[$locale]);
        } else {
            $values[$locale] = $value;
        }

        return new self($values, $this->raw);
    }

    public function withRaw(?string $raw): self
    {
        return new self($this->values, $raw);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->values;
    }
}
