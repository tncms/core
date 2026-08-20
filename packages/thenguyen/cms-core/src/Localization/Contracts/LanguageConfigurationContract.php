<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Contracts;

use TheNguyen\CMS\Localization\LocaleMetadata;

/**
 * CORE-L10N.1B — the single read-only view of TN CMS's global multilingual configuration.
 *
 * Every global localization decision is sourced HERE, and this contract is backed exclusively
 * by the existing Core Language authority ({@see \TheNguyen\CMS\Services\LanguageManager} +
 * the `cms_settings` `language.*` keys). It introduces NO second configuration store and MUST
 * NOT fall back to plugin configuration for any global value.
 *
 * Plugins may read this; they may never provide or override any value it exposes.
 */
interface LanguageConfigurationContract
{
    /** Whether the site is multilingual (more than one enabled public locale). */
    public function multilingualEnabled(): bool;

    /**
     * The enabled (active) public locale codes, e.g. ['en', 'vi'].
     *
     * @return array<int, string>
     */
    public function enabledLocales(): array;

    /** The platform default locale code. */
    public function defaultLocale(): string;

    /**
     * The platform fallback locale. TN CMS uses the default locale as the deterministic
     * fallback floor (there is no separate fallback authority), so this equals
     * {@see defaultLocale()} — kept as a distinct method so a future policy can diverge
     * without changing callers.
     */
    public function fallbackLocale(): string;

    /** Whether $code is an enabled public locale (normalized before comparison). */
    public function isEnabled(string $code): bool;

    /** Normalize a raw locale code the same way the language authority does. */
    public function normalize(?string $code): string;

    /** Presentation metadata for a single locale, or null when it is not a known language. */
    public function metadata(string $code): ?LocaleMetadata;

    /**
     * Presentation metadata for every enabled locale, in configured order.
     *
     * @return array<int, LocaleMetadata>
     */
    public function allMetadata(): array;

    /**
     * The URL path prefix for $code under the current prefix policy: '' for the default
     * locale when the default is unprefixed, otherwise '/{code}'. This is the ONLY prefix
     * authority — plugins never compute prefixes.
     */
    public function prefixFor(string $code): string;

    /** Whether the default locale is itself prefixed (`language.prefix_default`). */
    public function prefixesDefaultLocale(): bool;

    /**
     * The active routing strategy key (e.g. 'prefix' | 'session'). Backed by the
     * `language.routing_strategy` setting; defaults to 'prefix'. Validation/fail-closed
     * behaviour for an unknown value is enforced by the strategy registry, not here.
     */
    public function routingStrategy(): string;
}
