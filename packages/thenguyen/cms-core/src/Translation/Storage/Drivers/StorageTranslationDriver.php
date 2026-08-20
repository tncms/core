<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Drivers;

use TheNguyen\CMS\Translation\Contracts\TranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;

/**
 * Bridges a {@see LocalizedStorageInterface} backend into the Translation Engine
 * as a read {@see TranslationDriverInterface} (Phase 8.1).
 *
 * Registering an instance on the {@see \TheNguyen\CMS\Translation\Drivers\TranslationDriverRegistry}
 * is how the resolver "resolves through the storage driver when appropriate": a
 * caller (or config) selects this driver by name in the context, and the resolver
 * reads stored values, then applies the shared {@see \TheNguyen\CMS\Translation\Support\FallbackChain}.
 * It never applies fallback itself — it just surfaces what storage holds.
 *
 * Future backends (JSON/YAML/Remote/AI) reuse this exact bridge — implement
 * {@see LocalizedStorageInterface}, wrap it here under a name, register it.
 */
final class StorageTranslationDriver implements TranslationDriverInterface
{
    public function __construct(
        private readonly LocalizedStorageInterface $storage,
        private readonly ?string $name = null,
    ) {
    }

    public function name(): string
    {
        return $this->name ?? $this->storage->name();
    }

    public function get(TranslationKey $key, string $locale, TranslationContext $context): ?string
    {
        return $this->storage->getLocale($key, $locale);
    }

    public function has(TranslationKey $key, string $locale, TranslationContext $context): bool
    {
        return $this->storage->exists($key, $locale);
    }

    public function all(TranslationKey $key, TranslationContext $context): LocalizedValue
    {
        return $this->storage->get($key);
    }
}
