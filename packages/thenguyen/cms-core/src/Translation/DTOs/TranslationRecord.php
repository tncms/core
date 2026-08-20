<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * One localized record for a single `(entity, locale)` — the unit a relational
 * translation driver reads and writes. `fields` is the map of translatable
 * column values (e.g. title/slug/excerpt/content/SEO for content); identity
 * columns and timestamps are NOT part of it. Immutable.
 */
final class TranslationRecord
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly int|string $entityId,
        public readonly string $locale,
        public readonly array $fields = [],
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function make(int|string $entityId, string $locale, array $fields = []): self
    {
        return new self($entityId, $locale, $fields);
    }

    public function field(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function hasField(string $name): bool
    {
        return array_key_exists($name, $this->fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function withFields(array $fields): self
    {
        return new self($this->entityId, $this->locale, $fields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity_id' => $this->entityId,
            'locale' => $this->locale,
            'fields' => $this->fields,
        ];
    }
}
