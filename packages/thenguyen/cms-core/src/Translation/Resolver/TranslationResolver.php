<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Resolver;

use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;
use TheNguyen\CMS\Translation\Drivers\TranslationDriverRegistry;
use TheNguyen\CMS\Translation\Support\FallbackChain;
use TheNguyen\CMS\Translation\Support\TranslationEvents;

/**
 * The single entry point for reading translations.
 *
 * Responsibilities: normalise the {@see TranslationContext} (fill locales from
 * the registry + config), consult the request cache, select a driver, load the
 * value and walk the {@see FallbackChain}. Emits best-effort lifecycle events.
 * No module reads translations without going through this resolver.
 */
final class TranslationResolver implements TranslationResolverInterface
{
    /**
     * @param array{enabled?: bool, cache?: bool, default_driver?: ?string, fallback_chain?: array<int, string>} $config
     */
    public function __construct(
        private readonly LocaleRegistryInterface $registry,
        private readonly TranslationDriverRegistry $drivers,
        private readonly TranslationCacheInterface $cache,
        private readonly FallbackChain $chain,
        private readonly array $config = [],
    ) {
    }

    public function resolve(TranslationKey $key, ?TranslationContext $context = null): TranslationResult
    {
        $context = $this->normalize($context);

        TranslationEvents::fire(TranslationEvents::RESOLVING, $context, $key);

        $cacheable = ($this->config['enabled'] ?? true) && ($this->config['cache'] ?? true);
        $cacheKey = $this->cacheKey($key, $context);

        if ($cacheable && $this->cache->has($cacheKey)) {
            $hit = ($this->cache->get($cacheKey) ?? TranslationResult::miss())->withCache(true);
            TranslationEvents::fire(TranslationEvents::CACHE_HIT, $key, $context);
            TranslationEvents::fire(TranslationEvents::RESOLVED, $hit, $context, $key);

            return $hit;
        }

        if ($cacheable) {
            TranslationEvents::fire(TranslationEvents::CACHE_MISS, $key, $context);
        }

        $driver = $this->drivers->resolve($context->driver);
        $value = $driver !== null ? $driver->all($key, $context) : new LocalizedValue([]);

        $result = $this->chain->resolve($value, $context);

        if ($cacheable) {
            $this->cache->put($cacheKey, $result);
        }

        TranslationEvents::fire(TranslationEvents::RESOLVED, $result, $context, $key);

        return $result;
    }

    public function resolveValue(LocalizedValue $value, ?TranslationContext $context = null): TranslationResult
    {
        $context = $this->normalize($context);

        TranslationEvents::fire(TranslationEvents::RESOLVING, $context, null);

        $result = $this->chain->resolve($value, $context);

        TranslationEvents::fire(TranslationEvents::RESOLVED, $result, $context, null);

        return $result;
    }

    /**
     * Fill any locale/driver/chain the caller left null from the registry + config,
     * so an empty context still resolves sensibly.
     */
    private function normalize(?TranslationContext $context): TranslationContext
    {
        $default = $this->registry->default();
        $fallback = $this->registry->fallback() ?? $default;

        if ($context === null) {
            return new TranslationContext(
                requestedLocale: $default,
                fallbackLocale: $fallback,
                defaultLocale: $default,
                driver: $this->drivers->defaultName() ?? ($this->config['default_driver'] ?? null),
                fallbackChain: $this->configuredChain(),
            );
        }

        return new TranslationContext(
            requestedLocale: $context->requestedLocale ?? $default,
            fallbackLocale: $context->fallbackLocale ?? $fallback,
            defaultLocale: $context->defaultLocale ?? $default,
            driver: $context->driver ?? $this->drivers->defaultName() ?? ($this->config['default_driver'] ?? null),
            namespace: $context->namespace,
            fallbackChain: $context->fallbackChain,
            metadata: $context->metadata,
        );
    }

    /**
     * @return array<int, string>
     */
    private function configuredChain(): array
    {
        $chain = $this->config['fallback_chain'] ?? null;

        return is_array($chain) && $chain !== []
            ? array_values($chain)
            : ['requested', 'site_fallback', 'default', 'raw'];
    }

    private function cacheKey(TranslationKey $key, TranslationContext $context): string
    {
        return implode('|', [
            $context->driver ?? 'default',
            $key->toString(),
            $context->requestedLocale ?? '',
            $context->fallbackLocale ?? '',
            $context->defaultLocale ?? '',
            implode(',', $context->fallbackChain),
        ]);
    }
}
