<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage;

use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationRepositoryInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\Support\LocalizedField;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedRecordInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Support\LocalizedStorageEvents;
use TheNguyen\CMS\Translation\Storage\Validation\LocalizedValueValidator;

/**
 * The write/read orchestration layer over a {@see LocalizedStorageInterface}
 * backend (Phase 8.1).
 *
 * This is the entry point modules use for localized persistence. It composes the
 * backend with validation, lifecycle events and cache invalidation — the backend
 * stays a dumb store; the policy lives here. It implements the engine's existing
 * {@see TranslationRepositoryInterface} seam (so it is a drop-in for the no-op
 * repository) while adding richer, field-aware methods.
 *
 * It is intentionally NOT bound to {@see TranslationRepositoryInterface} by
 * default — the container keeps the no-op binding for exact backward
 * compatibility. Callers opt in via `cms.translation.storage.repository`.
 */
final class LocalizedStorageRepository implements TranslationRepositoryInterface
{
    /**
     * @param array{invalidate_cache_on_write?: bool} $config
     */
    public function __construct(
        private readonly LocalizedStorageInterface $storage,
        private readonly LocalizedValueValidator $validator,
        private readonly TranslationCacheInterface $cache,
        private readonly array $config = [],
    ) {
    }

    // ------------------------------------------------------------------
    // TranslationRepositoryInterface
    // ------------------------------------------------------------------

    /** All stored locales for a key, or null when nothing is stored. */
    public function find(TranslationKey $key): ?LocalizedValue
    {
        $value = $this->storage->get($key);

        return $value->isEmpty() ? null : $value;
    }

    /** Validate and persist the full locale map, firing lifecycle events. */
    public function save(TranslationKey $key, LocalizedValue $value): void
    {
        $this->validator->validate($key, $value);

        $existed = $this->storage->exists($key);
        $before = $existed ? LocalizedStorageEvents::UPDATING : LocalizedStorageEvents::CREATING;
        $after = $existed ? LocalizedStorageEvents::UPDATED : LocalizedStorageEvents::CREATED;

        LocalizedStorageEvents::fire($before, $key, $value);
        $this->storage->put($key, $value);
        LocalizedStorageEvents::fire($after, $key, $value);

        $this->invalidate($key);
    }

    /** Remove every locale for a key, firing the deleted event when it existed. */
    public function forget(TranslationKey $key): void
    {
        if (! $this->storage->exists($key)) {
            return;
        }

        $this->storage->forget($key);
        LocalizedStorageEvents::fire(LocalizedStorageEvents::DELETED, $key);

        $this->invalidate($key);
    }

    // ------------------------------------------------------------------
    // Richer, field-aware API
    // ------------------------------------------------------------------

    /** A read-only record (key + stored value) snapshot. */
    public function record(TranslationKey $key): LocalizedRecordInterface
    {
        return new LocalizedRecord($key, $this->storage->get($key));
    }

    /** Whether the key (optionally one locale) has any stored value. */
    public function exists(TranslationKey $key, ?string $locale = null): bool
    {
        return $this->storage->exists($key, $locale);
    }

    /**
     * Write (or, with null, remove) a single locale without disturbing the
     * others. Fires create/update lifecycle events for the affected key.
     */
    public function putLocale(TranslationKey $key, string $locale, ?string $value): void
    {
        if ($value !== null) {
            $this->validator->validate($key, new LocalizedValue([$locale => $value]));
        }

        $existed = $this->storage->exists($key);
        $before = $existed ? LocalizedStorageEvents::UPDATING : LocalizedStorageEvents::CREATING;
        $after = $existed ? LocalizedStorageEvents::UPDATED : LocalizedStorageEvents::CREATED;

        LocalizedStorageEvents::fire($before, $key, new LocalizedValue($value === null ? [] : [$locale => $value]));
        $this->storage->putLocale($key, $locale, $value);
        LocalizedStorageEvents::fire($after, $key, $this->storage->get($key));

        $this->invalidate($key);
    }

    /** Remove a single locale for a key. */
    public function forgetLocale(TranslationKey $key, string $locale): void
    {
        if (! $this->storage->exists($key, $locale)) {
            return;
        }

        $this->storage->forgetLocale($key, $locale);
        LocalizedStorageEvents::fire(LocalizedStorageEvents::DELETED, $key);

        $this->invalidate($key);
    }

    // ------------------------------------------------------------------
    // LocalizedField persistence
    // ------------------------------------------------------------------

    /**
     * Persist a {@see LocalizedField}. The field must carry its key (the storage
     * address); pass one explicitly when the field was built without it.
     */
    public function saveField(LocalizedField $field, ?TranslationKey $key = null): void
    {
        $key ??= $field->key;

        if ($key === null) {
            throw new \InvalidArgumentException('Cannot persist a LocalizedField without a TranslationKey.');
        }

        $this->save($key, $field->value);
    }

    /** Load a key's stored value as a {@see LocalizedField}. */
    public function findField(TranslationKey $key): LocalizedField
    {
        return new LocalizedField($this->storage->get($key), $key);
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    /**
     * Invalidate resolved-translation cache after a write. The runtime cache is
     * request-scoped and keyed by driver+locale combinations, so a full flush is
     * the correct, cheap way to guarantee a subsequent read sees the new value.
     */
    private function invalidate(TranslationKey $key): void
    {
        if (($this->config['invalidate_cache_on_write'] ?? true) !== true) {
            return;
        }

        $this->cache->flush();
        LocalizedStorageEvents::fire(LocalizedStorageEvents::CACHE_INVALIDATED, $key);
    }
}
