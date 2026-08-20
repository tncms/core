<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content;

use TheNguyen\CMS\Translation\Content\Contracts\LocalizedContentContract;

/**
 * The single registry of localized content contracts (Phase 8.4).
 *
 * Every future module registers its localized fields here — there is no
 * module-specific hardcoding in core (core registers nothing itself). Once
 * registered, a content type's field definitions are discoverable by the manager,
 * the admin components, search/index builders and tooling.
 */
final class LocalizedContentRegistry
{
    /** @var array<string, array<string, LocalizedFieldDefinition>> type => name => definition */
    private array $fields = [];

    /** @var array<string, LocalizedContentContract> */
    private array $contracts = [];

    public function register(LocalizedContentContract $contract): static
    {
        $type = $contract->localizedContentType();
        $this->contracts[$type] = $contract;

        foreach ($contract->localizedFieldDefinitions() as $definition) {
            if ($definition instanceof LocalizedFieldDefinition) {
                $this->fields[$type][$definition->name] = $definition;
            }
        }

        return $this;
    }

    /**
     * Register field definitions for a type directly, without a contract class.
     *
     * @param array<int, LocalizedFieldDefinition> $definitions
     */
    public function registerType(string $type, array $definitions): static
    {
        foreach ($definitions as $definition) {
            if ($definition instanceof LocalizedFieldDefinition) {
                $this->fields[$type][$definition->name] = $definition;
            }
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->fields[$type]);
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return array_keys($this->fields);
    }

    /** @return array<string, LocalizedFieldDefinition> */
    public function fields(string $type): array
    {
        return $this->fields[$type] ?? [];
    }

    public function field(string $type, string $name): ?LocalizedFieldDefinition
    {
        return $this->fields[$type][$name] ?? null;
    }

    public function contract(string $type): ?LocalizedContentContract
    {
        return $this->contracts[$type] ?? null;
    }

    /** @return array<string, LocalizedFieldDefinition> */
    public function searchableFields(string $type): array
    {
        return array_filter($this->fields($type), static fn (LocalizedFieldDefinition $d): bool => $d->searchable);
    }

    /** @return array<string, LocalizedFieldDefinition> */
    public function indexableFields(string $type): array
    {
        return array_filter($this->fields($type), static fn (LocalizedFieldDefinition $d): bool => $d->indexable);
    }

    public function forget(string $type): static
    {
        unset($this->fields[$type], $this->contracts[$type]);

        return $this;
    }
}
