<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Contracts\LocaleRegistryInterface;

/**
 * Builds {@see LocaleOptions} from the engine's locale registry projection
 * (Phase 8.3).
 *
 * The single source of admin locales: ENABLED locales only (disabled ones are
 * excluded), ordered default-first. No locale is hardcoded, so the components
 * adapt to whatever the site has configured — one locale or fifty.
 */
final class LocaleOptionsResolver
{
    /**
     * @param array{locale_overflow_threshold?: int} $config
     */
    public function __construct(
        private readonly LocaleRegistryInterface $registry,
        private readonly array $config = [],
    ) {
    }

    public function resolve(): LocaleOptions
    {
        $default = $this->registry->default();
        $fallback = $this->registry->fallback();
        $enabled = $this->registry->enabled();

        // Default first, then the remaining enabled locales in registry order.
        $ordered = [];
        if ($default !== null && in_array($default, $enabled, true)) {
            $ordered[] = $default;
        }
        foreach ($enabled as $code) {
            if ($code !== $default) {
                $ordered[] = $code;
            }
        }

        $options = [];
        foreach ($ordered as $code) {
            $definition = $this->registry->get($code);
            $options[] = new LocaleOption(
                $code,
                $definition?->label ?? $code,
                $definition?->native,
                $code === $default,
                $code === $fallback,
            );
        }

        return new LocaleOptions($options);
    }

    public function overflowThreshold(): int
    {
        return (int) ($this->config['locale_overflow_threshold'] ?? 5);
    }
}
