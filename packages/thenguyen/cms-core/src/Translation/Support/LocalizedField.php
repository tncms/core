<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Support;

use TheNguyen\CMS\Translation\Contracts\TranslationResolverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;

/**
 * Reusable, immutable representation of ONE translatable field (title, slug,
 * excerpt, SEO fields, product name, ACF value, widget/menu label, …).
 *
 * This is the shape future models/casts will expose. Phase 8.0 deliberately does
 * NOT wire it into any existing model — it only defines the concept. It can
 * resolve standalone via {@see get()} (no container) or through the engine's
 * registered resolver via {@see resolve()}.
 */
final class LocalizedField
{
    public function __construct(
        public readonly LocalizedValue $value,
        public readonly ?TranslationKey $key = null,
    ) {
    }

    /**
     * @param array<string, string|null> $values locale => value
     */
    public static function make(array $values = [], ?string $raw = null, ?TranslationKey $key = null): self
    {
        return new self(new LocalizedValue($values, $raw), $key);
    }

    public static function fromRaw(?string $raw, ?TranslationKey $key = null): self
    {
        return new self(LocalizedValue::fromRaw($raw), $key);
    }

    /**
     * Standalone resolution — no container required. Applies the canonical
     * requested → fallback → default → raw chain using the supplied locales.
     */
    public function get(?string $locale, ?string $fallback = null, ?string $default = null): ?string
    {
        $context = new TranslationContext(
            requestedLocale: $locale,
            fallbackLocale: $fallback,
            defaultLocale: $default,
        );

        return (new FallbackChain)->resolve($this->value, $context)->value;
    }

    /**
     * Resolve through the engine's registered resolver (locale registry defaults,
     * events, cache-aware). Pass an explicit resolver, or leave null to pull the
     * bound one from the container.
     */
    public function resolve(?TranslationContext $context = null, ?TranslationResolverInterface $resolver = null): TranslationResult
    {
        $resolver ??= app(TranslationResolverInterface::class);

        return $resolver->resolveValue($this->value, $context);
    }

    public function withValue(string $locale, ?string $value): self
    {
        return new self($this->value->withValue($locale, $value), $this->key);
    }

    public function withKey(?TranslationKey $key): self
    {
        return new self($this->value, $key);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->value->toArray();
    }
}
