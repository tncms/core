<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use TheNguyen\CMS\Services\ExtensionIdentityResolver;

/**
 * One {@see ContentSeoProvider} per plugin, bound as `cms.content_seo`.
 *
 * The registering plugin is derived from the call site and must also own the
 * provider's class file, so a plugin cannot register on another's behalf.
 * Ownership is re-derived on every lookup: a deactivated plugin's provider is
 * never consulted.
 */
final class ContentSeoRegistry
{
    /** @var array<string, ContentSeoProvider> */
    private array $providers = [];

    private CallSitePlugin $callSite;

    public function __construct(private readonly ExtensionIdentityResolver $identity)
    {
        $this->callSite = new CallSitePlugin($identity, [__FILE__]);
    }

    public function register(ContentSeoProvider $provider): bool
    {
        $caller = $this->callSite->resolve();

        if (! is_string($caller) || $caller === '' || isset($this->providers[$caller])) {
            return false;
        }

        if ($this->identity->ownerOf($provider) !== $caller) {
            return false;
        }

        $this->providers[$caller] = $provider;

        return true;
    }

    public function providerFor(string $owner): ?ContentSeoProvider
    {
        $provider = $this->providers[$owner] ?? null;

        return $provider !== null && $this->identity->ownerOf($provider) === $owner ? $provider : null;
    }
}
