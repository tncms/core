<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use TheNguyen\CMS\Content\Extension\CallSitePlugin;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use TheNguyen\CMS\Services\ExtensionManager;

/**
 * One {@see SitemapProvider} per plugin, bound as `cms.sitemap_providers`
 * (the {@see \TheNguyen\CMS\Content\Extension\ContentSeoRegistry} rule).
 *
 * The registering plugin is derived from the call site and must also own the
 * provider's class file. A second registration, a spoofed class or a
 * non-plugin caller is refused without replacing anything. On every lookup
 * the owner is re-derived and the persisted state must say the plugin is
 * active, so a stale worker never serves a deactivated plugin's sitemap.
 */
final class SitemapProviderRegistry
{
    public const CORE = 'core';

    /** @var array<string, SitemapProvider> */
    private array $providers = [];

    private CallSitePlugin $callSite;

    public function __construct(
        private readonly ExtensionIdentityResolver $identity,
        private readonly ExtensionManager $extensions,
    ) {
        $this->callSite = new CallSitePlugin($identity, [__FILE__]);
    }

    public function register(SitemapProvider $provider): bool
    {
        $caller = $this->callSite->resolve();

        if (! is_string($caller) || $caller === '' || $caller === self::CORE || isset($this->providers[$caller])) {
            return false;
        }

        if ($this->identity->ownerOf($provider) !== $caller) {
            return false;
        }

        $this->providers[$caller] = $provider;

        return true;
    }

    /** @return array<string, SitemapProvider> owner slug => provider, sorted by slug, active owners only */
    public function active(): array
    {
        $out = [];

        foreach ($this->providers as $owner => $provider) {
            if ($this->identity->ownerOf($provider) === $owner && $this->extensions->isPluginActive($owner)) {
                $out[$owner] = $provider;
            }
        }

        ksort($out, SORT_STRING);

        return $out;
    }
}
