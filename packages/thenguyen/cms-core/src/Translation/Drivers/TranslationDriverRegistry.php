<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Drivers;

use TheNguyen\CMS\Translation\Contracts\TranslationDriverInterface;

/**
 * Registry of available translation drivers, keyed by {@see TranslationDriverInterface::name()}.
 * The resolver selects a driver by name (context override → configured default →
 * first registered). Additive: modules register drivers without touching core.
 */
final class TranslationDriverRegistry
{
    /** @var array<string, TranslationDriverInterface> */
    private array $drivers = [];

    private ?string $default = null;

    public function register(TranslationDriverInterface $driver, bool $asDefault = false): static
    {
        $this->drivers[$driver->name()] = $driver;

        if ($asDefault || $this->default === null) {
            $this->default = $driver->name();
        }

        return $this;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->drivers);
    }

    public function get(string $name): ?TranslationDriverInterface
    {
        return $this->drivers[$name] ?? null;
    }

    public function setDefault(string $name): static
    {
        if ($this->has($name)) {
            $this->default = $name;
        }

        return $this;
    }

    public function defaultName(): ?string
    {
        return $this->default;
    }

    /** Resolve a driver by name, falling back to the configured default. */
    public function resolve(?string $name = null): ?TranslationDriverInterface
    {
        $name ??= $this->default;

        return $name !== null ? $this->get($name) : null;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->drivers);
    }
}
