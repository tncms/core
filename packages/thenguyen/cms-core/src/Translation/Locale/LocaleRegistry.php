<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Locale;

use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;
use TheNguyen\CMS\Translation\DTOs\LocaleDefinition;

/**
 * In-memory locale registry — the engine's single source of truth for the
 * default/fallback locales and the enabled set. No locale is hardcoded: entries
 * and the default/fallback codes are seeded from config('translation') by the
 * service provider.
 */
final class LocaleRegistry implements LocaleRegistryInterface
{
    /** @var array<string, LocaleDefinition> */
    private array $locales = [];

    private ?string $default = null;

    private ?string $fallback = null;

    public function register(LocaleDefinition $locale): static
    {
        if (! array_key_exists($locale->code, $this->locales)) {
            $this->locales[$locale->code] = $locale;
        }

        return $this;
    }

    public function replace(LocaleDefinition $locale): static
    {
        $this->locales[$locale->code] = $locale;

        return $this;
    }

    public function remove(string $code): static
    {
        unset($this->locales[$code]);

        if ($this->default === $code) {
            $this->default = null;
        }
        if ($this->fallback === $code) {
            $this->fallback = null;
        }

        return $this;
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, $this->locales);
    }

    public function get(string $code): ?LocaleDefinition
    {
        return $this->locales[$code] ?? null;
    }

    public function all(): array
    {
        return $this->locales;
    }

    public function enabled(): array
    {
        $codes = [];
        foreach ($this->locales as $code => $locale) {
            if ($locale->enabled) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function labels(): array
    {
        $labels = [];
        foreach ($this->locales as $code => $locale) {
            $labels[$code] = $locale->label;
        }

        return $labels;
    }

    public function default(): ?string
    {
        return $this->default;
    }

    public function setDefault(string $code): static
    {
        $this->default = $code;

        return $this;
    }

    public function fallback(): ?string
    {
        return $this->fallback;
    }

    public function setFallback(string $code): static
    {
        $this->fallback = $code;

        return $this;
    }
}
