<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation;

use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Drivers\TranslationDriverRegistry;
use TheNguyen\CMS\Translation\Support\TranslationEvents;

/**
 * Facade-friendly coordinator over the translation engine — the `cms.translation`
 * singleton. It exposes the underlying registry/resolver/cache/drivers and a few
 * convenience shortcuts, so consumers depend on one entry point rather than
 * wiring the individual services themselves.
 */
final class TranslationManager
{
    public function __construct(
        private readonly LocaleRegistryInterface $registry,
        private readonly TranslationResolverInterface $resolver,
        private readonly TranslationCacheInterface $cache,
        private readonly TranslationDriverRegistry $drivers,
    ) {
    }

    public function registry(): LocaleRegistryInterface
    {
        return $this->registry;
    }

    public function resolver(): TranslationResolverInterface
    {
        return $this->resolver;
    }

    public function cache(): TranslationCacheInterface
    {
        return $this->cache;
    }

    public function drivers(): TranslationDriverRegistry
    {
        return $this->drivers;
    }

    // --- Convenience shortcuts ----------------------------------------------

    public function resolve(TranslationKey $key, ?TranslationContext $context = null): TranslationResult
    {
        return $this->resolver->resolve($key, $context);
    }

    public function resolveValue(LocalizedValue $value, ?TranslationContext $context = null): TranslationResult
    {
        return $this->resolver->resolveValue($value, $context);
    }

    /** @return array<int, string> enabled locale codes */
    public function locales(): array
    {
        return $this->registry->enabled();
    }

    public function defaultLocale(): ?string
    {
        return $this->registry->default();
    }

    public function fallbackLocale(): ?string
    {
        return $this->registry->fallback();
    }

    /** Flush the request cache and announce the invalidation (best effort). */
    public function flush(): void
    {
        $this->cache->flush();
        TranslationEvents::fire(TranslationEvents::INVALIDATED, null);
    }
}
