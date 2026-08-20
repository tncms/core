<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Registries;

/**
 * The core catalog of section types (theme-architecture `13`/`14`).
 *
 * Source of truth is `config/cms-sections.php` — an array keyed by section
 * `type`, each entry carrying a settings schema, fields schema, binding mode,
 * view, rendered components, and `since`. The {@see \TheNguyen\CMS\Services\SectionResolver}
 * validates + defaults section nodes against this registry; the builder/AI use
 * it for discovery and validation.
 *
 * Themes MAY extend the catalog later (theme-architecture `20` Rule 16); v1 is
 * core-only.
 */
class SectionRegistry
{
    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    public function __construct(private readonly array $sections = []) {}

    /**
     * All registered section entries, keyed by type.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->sections;
    }

    /**
     * The registered section types.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->sections);
    }

    public function has(string $type): bool
    {
        return isset($this->sections[$type]);
    }

    /**
     * A full section entry, or null when the type is unknown.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $type): ?array
    {
        return $this->sections[$type] ?? null;
    }

    /**
     * The settings schema for a type (empty when unknown).
     *
     * @return array<string, array<string, mixed>>
     */
    public function settingsSchema(string $type): array
    {
        $schema = $this->sections[$type]['settings'] ?? [];

        return is_array($schema) ? $schema : [];
    }

    /**
     * The fields schema for a type (empty when unknown).
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsSchema(string $type): array
    {
        $schema = $this->sections[$type]['fields'] ?? [];

        return is_array($schema) ? $schema : [];
    }

    /**
     * The binding mode for a type (`data` | `query` | `context`), defaulting to
     * `data`.
     */
    public function bindingMode(string $type): string
    {
        $mode = $this->sections[$type]['binding'] ?? 'data';

        return is_string($mode) ? $mode : 'data';
    }

    /**
     * The default settings values for a type (from its schema). Used by the
     * layout editor when adding a new section.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(string $type): array
    {
        $out = [];

        foreach ($this->settingsSchema($type) as $key => $spec) {
            $out[$key] = is_array($spec) ? ($spec['default'] ?? null) : null;
        }

        return $out;
    }

    /**
     * The default field values for a type (from its schema). Scalars use their
     * declared default; repeaters default to an empty list.
     *
     * @return array<string, mixed>
     */
    public function defaultFields(string $type): array
    {
        $out = [];

        foreach ($this->fieldsSchema($type) as $key => $spec) {
            $out[$key] = $this->defaultFieldValue(is_array($spec) ? $spec : []);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function defaultFieldValue(array $spec): mixed
    {
        if (array_key_exists('default', $spec)) {
            return $spec['default'];
        }

        return match ($spec['type'] ?? 'text') {
            'repeater' => [],
            'boolean' => false,
            'number' => null,
            'media', 'link' => null,
            default => '',
        };
    }
}
