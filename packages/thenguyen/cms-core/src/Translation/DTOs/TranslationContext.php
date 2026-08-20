<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * Immutable description of a single resolution request.
 *
 * Any null locale/driver is filled in by the resolver from the locale registry
 * and config at resolve time, so callers may pass an empty context and still get
 * sensible behaviour. `fallbackChain` names the stages to attempt, in order.
 *
 * Mutation returns a new instance.
 */
final class TranslationContext
{
    /**
     * @param array<int, string> $fallbackChain ordered stage names (see config('translation.fallback_chain'))
     * @param array<string, mixed> $metadata free-form, driver-specific hints
     */
    public function __construct(
        public readonly ?string $requestedLocale = null,
        public readonly ?string $fallbackLocale = null,
        public readonly ?string $defaultLocale = null,
        public readonly ?string $driver = null,
        public readonly ?string $namespace = null,
        public readonly array $fallbackChain = ['requested', 'site_fallback', 'default', 'raw'],
        public readonly array $metadata = [],
    ) {
    }

    public static function for(?string $requestedLocale = null): self
    {
        return new self(requestedLocale: $requestedLocale);
    }

    public function withRequestedLocale(?string $locale): self
    {
        return $this->copy(['requestedLocale' => $locale]);
    }

    public function withFallbackLocale(?string $locale): self
    {
        return $this->copy(['fallbackLocale' => $locale]);
    }

    public function withDefaultLocale(?string $locale): self
    {
        return $this->copy(['defaultLocale' => $locale]);
    }

    public function withDriver(?string $driver): self
    {
        return $this->copy(['driver' => $driver]);
    }

    public function withNamespace(?string $namespace): self
    {
        return $this->copy(['namespace' => $namespace]);
    }

    /**
     * @param array<int, string> $chain
     */
    public function withFallbackChain(array $chain): self
    {
        return $this->copy(['fallbackChain' => array_values($chain)]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->copy(['metadata' => $metadata]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function copy(array $overrides): self
    {
        // array_key_exists (not ??) so a wither can explicitly set a field to null.
        $pick = fn (string $key, mixed $current): mixed => array_key_exists($key, $overrides) ? $overrides[$key] : $current;

        return new self(
            $pick('requestedLocale', $this->requestedLocale),
            $pick('fallbackLocale', $this->fallbackLocale),
            $pick('defaultLocale', $this->defaultLocale),
            $pick('driver', $this->driver),
            $pick('namespace', $this->namespace),
            $pick('fallbackChain', $this->fallbackChain),
            $pick('metadata', $this->metadata),
        );
    }
}
