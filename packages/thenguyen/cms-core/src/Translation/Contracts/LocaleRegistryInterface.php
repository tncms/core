<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocaleDefinition;

/**
 * Central, in-memory registry of the locales the platform knows about.
 *
 * It is the single source of truth for the engine's default/fallback locales
 * and the enabled set. Seeded from config('translation'); modules and future
 * phases mutate it through this contract only.
 *
 * @since 1.0
 *
 * @stable
 */
interface LocaleRegistryInterface
{
    public function register(LocaleDefinition $locale): static;

    /** Register, replacing any existing entry with the same code. */
    public function replace(LocaleDefinition $locale): static;

    public function remove(string $code): static;

    public function has(string $code): bool;

    public function get(string $code): ?LocaleDefinition;

    /** @return array<string, LocaleDefinition> code => definition */
    public function all(): array;

    /** @return array<int, string> enabled locale codes, registration order */
    public function enabled(): array;

    /** @return array<string, string> code => label */
    public function labels(): array;

    public function default(): ?string;

    public function setDefault(string $code): static;

    public function fallback(): ?string;

    public function setFallback(string $code): static;
}
