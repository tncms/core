<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content;

use TheNguyen\CMS\Translation\Content\Enums\LocalizedFallbackPolicy;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityRepositoryInterface;
use TheNguyen\CMS\Translation\Entity\Contracts\LocalizedEntityResolverInterface;

/**
 * The entry point that resolves localized content through the canonical contract
 * (Phase 8.4).
 *
 * Its defining responsibility is the backward-compatible resolution order that
 * lets a module adopt the contract WITHOUT migrating data:
 *
 *   1. Localized storage — the exact value stored for the requested locale.
 *   2. Legacy field      — the module's pre-migration, non-localized column
 *                          (supplied by the caller as a provider).
 *   3. Fallback chain    — the engine chain, restricted to the field's declared
 *                          {@see LocalizedFallbackPolicy}.
 *
 * It reads through the Phase 8.2 entity repository/resolver only (no direct table
 * access) and applies no fallback of its own — step 3 is the engine's chain.
 */
final class LocalizedContentManager
{
    public function __construct(
        private readonly LocalizedContentRegistry $registry,
        private readonly LocalizedEntityRepositoryInterface $repository,
        private readonly LocalizedEntityResolverInterface $resolver,
    ) {
    }

    public function registry(): LocalizedContentRegistry
    {
        return $this->registry;
    }

    /** @return array<string, LocalizedFieldDefinition> */
    public function fields(string $type): array
    {
        return $this->registry->fields($type);
    }

    public function definition(string $type, string $field): ?LocalizedFieldDefinition
    {
        return $this->registry->field($type, $field);
    }

    public function validationPolicy(string $type, string $field): ?LocalizedValidationPolicy
    {
        return $this->registry->field($type, $field)?->validationPolicy();
    }

    /**
     * Resolve a field for an entity honoring the compatibility order
     * (localized storage → legacy field → fallback chain).
     *
     * @param  callable():(?string)|null  $legacy  reads the module's pre-migration value
     */
    public function resolve(LocalizedEntityInterface $entity, string $field, ?string $locale = null, ?callable $legacy = null): ?string
    {
        $locale ??= $this->currentLocale();

        // 1. Localized storage — exact requested locale (no fallback yet).
        $exact = $this->repository->getField($entity, $field)->get($locale);
        if ($exact !== null) {
            return $exact;
        }

        // 2. Legacy field — the pre-migration column value, if the caller has one.
        if ($legacy !== null) {
            $legacyValue = $legacy();
            if (is_string($legacyValue) && $legacyValue !== '') {
                return $legacyValue;
            }
        }

        // 3. Fallback chain — the engine chain, scoped by the field's policy.
        $policy = $this->registry->field($entity->localizedType(), $field)?->fallback
            ?? LocalizedFallbackPolicy::Chain;

        $context = (new TranslationContext(requestedLocale: $locale))->withFallbackChain($policy->stages());

        return $this->resolver->resolve($entity, $field, $context)->value;
    }

    private function currentLocale(): string
    {
        if (function_exists('current_locale')) {
            return current_locale();
        }

        return (string) app('cms.language')->currentCode();
    }
}
