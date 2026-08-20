<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;

/**
 * Entity-granular persistence over the shared localized storage (Phase 8.2).
 *
 * Pure persistence: it validates and reads/writes an entity's fields via the
 * {@see \TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface}
 * backend, addressing them through the single mapping layer. It fires no events
 * and touches no cache — that policy belongs to the
 * {@see \TheNguyen\CMS\Translation\Entity\LocalizedEntityManager}.
 */
interface LocalizedEntityRepositoryInterface
{
    /**
     * All declared fields for an entity as `field => LocalizedValue` (every
     * declared field present; unstored fields map to an empty value).
     *
     * @return array<string, LocalizedValue>
     */
    public function getFields(LocalizedEntityInterface $entity): array;

    /** One field's full locale map (empty when nothing is stored). */
    public function getField(LocalizedEntityInterface $entity, string $field): LocalizedValue;

    /**
     * Persist several fields at once (each is a full-locale replace of that field).
     *
     * @param  array<string, LocalizedValue>  $fields
     */
    public function saveFields(LocalizedEntityInterface $entity, array $fields): void;

    /** Persist one field (full-locale replace). */
    public function saveField(LocalizedEntityInterface $entity, string $field, LocalizedValue $value): void;

    /** Write (or, with null, remove) a single locale of one field. */
    public function saveFieldLocale(LocalizedEntityInterface $entity, string $field, string $locale, ?string $value): void;

    /** Remove every field/locale for an entity. */
    public function deleteEntity(LocalizedEntityInterface $entity): void;

    /** Remove one field (all locales) of an entity. */
    public function deleteField(LocalizedEntityInterface $entity, string $field): void;

    /** Whether the entity (optionally one field) has any stored value. */
    public function exists(LocalizedEntityInterface $entity, ?string $field = null): bool;
}
