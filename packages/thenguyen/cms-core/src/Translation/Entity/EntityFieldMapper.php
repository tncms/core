<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity;

use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Storage\Support\FieldAddress;

/**
 * The single mapping layer: Entity → LocalizedField/TranslationKey → Storage.
 *
 * Every entity component (repository, resolver, manager, traits) addresses stored
 * values through this one class, so the entity→key convention lives in exactly
 * one place and no module can bypass it. The key is
 * `{type}::{id}:{field}` via {@see FieldAddress}.
 */
final class EntityFieldMapper
{
    /** The storage key for one field of an entity. */
    public function key(LocalizedEntityInterface $entity, string $field): TranslationKey
    {
        return FieldAddress::key($entity->localizedType(), $entity->localizedKey(), $field);
    }

    /**
     * Storage keys for many fields of one entity, keyed by field name.
     *
     * @param  array<int, string>  $fields
     * @return array<string, TranslationKey>
     */
    public function keys(LocalizedEntityInterface $entity, array $fields): array
    {
        $keys = [];

        foreach ($fields as $field) {
            $keys[$field] = $this->key($entity, $field);
        }

        return $keys;
    }
}
