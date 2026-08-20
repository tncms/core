<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\BatchLocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;

/**
 * Reusable read helpers for localized entities (Phase 8.2). No module wiring —
 * these are the building blocks future modules use to avoid N+1 translation
 * loads (e.g. hydrating a list of posts).
 *
 * When the backend supports {@see BatchLocalizedStorageInterface}, loading many
 * entities collapses to a single query.
 */
final class LocalizedEntityQuery
{
    public function __construct(
        private readonly LocalizedStorageInterface $storage,
        private readonly EntityFieldMapper $mapper,
    ) {
    }

    /**
     * Load the localized fields for many entities at once.
     *
     * @param  array<int, LocalizedEntityInterface>  $entities
     * @return array<string, array<string, LocalizedValue>>  entity address ("type::id")
     *                                                        => (field => value)
     */
    public function loadMany(array $entities): array
    {
        $keyIndex = [];   // "type::id" => [field => TranslationKey]
        $allKeys = [];

        foreach ($entities as $entity) {
            $address = $entity->localizedType().'::'.$entity->localizedKey();
            $fields = method_exists($entity, 'localizedFieldNames') ? $entity->localizedFieldNames() : [];

            foreach ($this->mapper->keys($entity, array_map('strval', $fields)) as $field => $key) {
                $keyIndex[$address][$field] = $key;
                $allKeys[] = $key;
            }
        }

        $values = $this->storage instanceof BatchLocalizedStorageInterface
            ? $this->storage->getMany($allKeys)
            : $this->perKey($allKeys);

        $out = [];
        foreach ($keyIndex as $address => $fields) {
            foreach ($fields as $field => $key) {
                $out[$address][$field] = $values[$key->toString()] ?? new LocalizedValue([]);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, \TheNguyen\CMS\Translation\DTOs\TranslationKey>  $keys
     * @return array<string, LocalizedValue>
     */
    private function perKey(array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $out[$key->toString()] = $this->storage->get($key);
        }

        return $out;
    }
}
