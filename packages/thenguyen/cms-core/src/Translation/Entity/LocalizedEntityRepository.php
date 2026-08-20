<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityRepositoryInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\BatchLocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Validation\LocalizedValueValidator;

/**
 * Entity-granular persistence over the shared {@see LocalizedStorageInterface}
 * backend (Phase 8.2). Pure storage + validation — no events, no cache (the
 * manager owns that policy). All addressing goes through {@see EntityFieldMapper},
 * so this never bypasses the mapping layer.
 *
 * Reads of a whole entity are batched into one query when the backend supports
 * {@see BatchLocalizedStorageInterface}; otherwise it falls back to per-field gets.
 */
final class LocalizedEntityRepository implements LocalizedEntityRepositoryInterface
{
    public function __construct(
        private readonly LocalizedStorageInterface $storage,
        private readonly LocalizedValueValidator $validator,
        private readonly EntityFieldMapper $mapper,
    ) {
    }

    public function getFields(LocalizedEntityInterface $entity): array
    {
        $fields = $this->fieldNames($entity);

        if ($fields === []) {
            return [];
        }

        $keys = $this->mapper->keys($entity, $fields);

        if ($this->storage instanceof BatchLocalizedStorageInterface) {
            $values = $this->storage->getMany(array_values($keys));

            $out = [];
            foreach ($keys as $field => $key) {
                $out[$field] = $values[$key->toString()] ?? new LocalizedValue([]);
            }

            return $out;
        }

        $out = [];
        foreach ($keys as $field => $key) {
            $out[$field] = $this->storage->get($key);
        }

        return $out;
    }

    public function getField(LocalizedEntityInterface $entity, string $field): LocalizedValue
    {
        return $this->storage->get($this->mapper->key($entity, $field));
    }

    public function saveFields(LocalizedEntityInterface $entity, array $fields): void
    {
        foreach ($fields as $field => $value) {
            $this->saveField($entity, (string) $field, $value);
        }
    }

    public function saveField(LocalizedEntityInterface $entity, string $field, LocalizedValue $value): void
    {
        $key = $this->mapper->key($entity, $field);
        $this->validator->validate($key, $value);
        $this->storage->put($key, $value);
    }

    public function saveFieldLocale(LocalizedEntityInterface $entity, string $field, string $locale, ?string $value): void
    {
        $key = $this->mapper->key($entity, $field);

        if ($value !== null) {
            $this->validator->validate($key, new LocalizedValue([$locale => $value]));
        }

        $this->storage->putLocale($key, $locale, $value);
    }

    public function deleteEntity(LocalizedEntityInterface $entity): void
    {
        foreach ($this->fieldNames($entity) as $field) {
            $this->storage->forget($this->mapper->key($entity, $field));
        }
    }

    public function deleteField(LocalizedEntityInterface $entity, string $field): void
    {
        $this->storage->forget($this->mapper->key($entity, $field));
    }

    public function exists(LocalizedEntityInterface $entity, ?string $field = null): bool
    {
        if ($field !== null) {
            return $this->storage->exists($this->mapper->key($entity, $field));
        }

        foreach ($this->fieldNames($entity) as $name) {
            if ($this->storage->exists($this->mapper->key($entity, $name))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared field names for an entity, or an empty list when it declares
     * none (a plain {@see LocalizedEntityInterface} with no field list).
     *
     * @return array<int, string>
     */
    private function fieldNames(LocalizedEntityInterface $entity): array
    {
        return method_exists($entity, 'localizedFieldNames')
            ? array_values(array_unique(array_map('strval', $entity->localizedFieldNames())))
            : [];
    }
}
