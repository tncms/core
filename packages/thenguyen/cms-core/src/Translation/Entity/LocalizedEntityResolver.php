<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Entity;

use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityResolverInterface;

/**
 * Resolves an entity field through the engine's storage-backed driver (Phase 8.2).
 *
 * It maps (entity, field) to a key via {@see EntityFieldMapper}, forces the
 * storage driver so the read comes from localized storage, and hands off to the
 * one {@see TranslationResolverInterface} — which owns locale resolution, the
 * fallback chain and the cache. This resolver adds no fallback of its own.
 */
final class LocalizedEntityResolver implements LocalizedEntityResolverInterface
{
    public function __construct(
        private readonly TranslationResolverInterface $resolver,
        private readonly EntityFieldMapper $mapper,
        private readonly string $driver = 'database',
    ) {
    }

    public function resolve(LocalizedEntityInterface $entity, string $field, ?TranslationContext $context = null): TranslationResult
    {
        $key = $this->mapper->key($entity, $field);
        $context = ($context ?? new TranslationContext)->withDriver($this->driver);

        return $this->resolver->resolve($key, $context);
    }

    public function value(LocalizedEntityInterface $entity, string $field, ?string $locale = null): ?string
    {
        $context = (new TranslationContext(requestedLocale: $locale))->withDriver($this->driver);

        return $this->resolve($entity, $field, $context)->value;
    }
}
