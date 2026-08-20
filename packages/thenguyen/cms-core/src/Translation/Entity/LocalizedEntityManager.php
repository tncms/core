<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity;

use InvalidArgumentException;
use TheNguyen\CMS\Translation\Contracts\PrunableTranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityRepositoryInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityResolverInterface;
use TheNguyen\CMS\Translation\Entity\Support\LocalizedEntityEvents;
use TheNguyen\CMS\Translation\Support\LocalizedField;

/**
 * The single entry point for localized entities (Phase 8.2).
 *
 * Owns the write policy the repository deliberately lacks — lifecycle events and
 * (key-level) cache invalidation — and the read/resolve surface backed by the
 * engine resolver. Modules and the entity traits go through this manager; nothing
 * writes entity translations by another path.
 */
final class LocalizedEntityManager
{
    /**
     * @param array{invalidate_cache_on_write?: bool} $config
     */
    public function __construct(
        private readonly LocalizedEntityRepositoryInterface $repository,
        private readonly LocalizedEntityResolverInterface $resolver,
        private readonly EntityFieldMapper $mapper,
        private readonly TranslationCacheInterface $cache,
        private readonly array $config = [],
    ) {
    }

    // ------------------------------------------------------------------
    // Writes (create / update)
    // ------------------------------------------------------------------

    /**
     * Persist translations for an entity, firing entity lifecycle events and
     * invalidating cache.
     *
     * Two input shapes:
     *   - multi-locale ($locale null): [field => [locale => value, …], …] — each
     *     field is a full-locale replace.
     *   - single-locale ($locale given): [field => value, …] — merges just that
     *     locale (null value clears it).
     *
     * @param array<string, mixed> $translations
     */
    public function saveTranslations(LocalizedEntityInterface $entity, array $translations, ?string $locale = null): void
    {
        if ($translations === []) {
            return;
        }

        $fields = array_map('strval', array_keys($translations));
        $existed = $this->repository->exists($entity);

        LocalizedEntityEvents::fire(
            $existed ? LocalizedEntityEvents::UPDATING : LocalizedEntityEvents::CREATING,
            $entity,
            $fields,
        );

        if ($locale === null) {
            $this->repository->saveFields($entity, $this->toLocalizedValues($translations));
        } else {
            foreach ($translations as $field => $value) {
                if (is_array($value)) {
                    throw new InvalidArgumentException(
                        "saveTranslations() with a \$locale expects a scalar value for field '{$field}'.",
                    );
                }

                $this->repository->saveFieldLocale($entity, (string) $field, $locale, $value === null ? null : (string) $value);
            }
        }

        LocalizedEntityEvents::fire(
            $existed ? LocalizedEntityEvents::UPDATED : LocalizedEntityEvents::CREATED,
            $entity,
            $fields,
        );

        $this->invalidate($entity, $fields);
    }

    // ------------------------------------------------------------------
    // Deletes
    // ------------------------------------------------------------------

    /** Delete all translations for an entity, or just one field. */
    public function delete(LocalizedEntityInterface $entity, ?string $field = null): void
    {
        $fields = $field !== null ? [$field] : $this->fieldNames($entity);

        LocalizedEntityEvents::fire(LocalizedEntityEvents::DELETING, $entity, $fields);

        if ($field !== null) {
            $this->repository->deleteField($entity, $field);
        } else {
            $this->repository->deleteEntity($entity);
        }

        LocalizedEntityEvents::fire(LocalizedEntityEvents::DELETED, $entity, $fields);

        $this->invalidate($entity, $fields);
    }

    // ------------------------------------------------------------------
    // Reads / resolve (developer API)
    // ------------------------------------------------------------------

    /**
     * The per-locale matrix of stored values: [locale => [field => value, …], …].
     *
     * @return array<string, array<string, string>>
     */
    public function translations(LocalizedEntityInterface $entity): array
    {
        $matrix = [];

        foreach ($this->repository->getFields($entity) as $field => $value) {
            foreach ($value->toArray() as $locale => $text) {
                $matrix[(string) $locale][(string) $field] = $text;
            }
        }

        return $matrix;
    }

    /**
     * The entity's fields as {@see LocalizedField} objects (raw, all locales).
     *
     * @return array<string, LocalizedField>
     */
    public function localizedFields(LocalizedEntityInterface $entity): array
    {
        $out = [];

        foreach ($this->repository->getFields($entity) as $field => $value) {
            $out[$field] = new LocalizedField($value, $this->mapper->key($entity, (string) $field));
        }

        return $out;
    }

    /** The resolved string for a field in $locale (null → current locale). */
    public function localizedValue(LocalizedEntityInterface $entity, string $field, ?string $locale = null): ?string
    {
        return $this->resolver->value($entity, $field, $locale);
    }

    /** The full resolution result for a field (value + locale + stage + cache). */
    public function resolveLocalized(LocalizedEntityInterface $entity, string $field, ?TranslationContext $context = null): TranslationResult
    {
        return $this->resolver->resolve($entity, $field, $context);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, LocalizedValue>
     */
    private function toLocalizedValues(array $translations): array
    {
        $map = [];

        foreach ($translations as $field => $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException(
                    "saveTranslations() without a \$locale expects a locale map for field '{$field}'.",
                );
            }

            $map[(string) $field] = new LocalizedValue($value);
        }

        return $map;
    }

    /**
     * Key-level cache invalidation when the cache supports it; otherwise a flush.
     *
     * @param array<int, string> $fields
     */
    private function invalidate(LocalizedEntityInterface $entity, array $fields): void
    {
        if (($this->config['invalidate_cache_on_write'] ?? true) !== true) {
            return;
        }

        if ($this->cache instanceof PrunableTranslationCacheInterface) {
            foreach ($fields as $field) {
                $this->cache->forgetContaining($this->mapper->key($entity, (string) $field)->toString());
            }
        } else {
            $this->cache->flush();
        }

        LocalizedEntityEvents::fire(LocalizedEntityEvents::CACHE_INVALIDATED, $entity);
    }

    /**
     * @return array<int, string>
     */
    private function fieldNames(LocalizedEntityInterface $entity): array
    {
        return method_exists($entity, 'localizedFieldNames')
            ? array_values(array_map('strval', $entity->localizedFieldNames()))
            : [];
    }
}
