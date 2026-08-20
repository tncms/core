<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Registries;

/**
 * The core catalog of reusable components (theme-architecture `13`).
 *
 * v1 is a thin catalog (source: `config/cms-components.php`) used for discovery
 * and validating a section's `renders` list. Rendering does not depend on it —
 * section views render components directly. Full prop schemas land with the
 * Page Builder.
 */
class ComponentRegistry
{
    /**
     * @param  array<string, array<string, mixed>>  $components
     */
    public function __construct(private readonly array $components = []) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->components;
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_keys($this->components);
    }

    public function has(string $name): bool
    {
        return isset($this->components[$name]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->components[$name] ?? null;
    }
}
