<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;

/**
 * CORE-L10N.1B — the built-in Home resolver.
 *
 * The site root has a target in every enabled locale (the localized home path), so it always
 * produces a descriptor for the home path ('/'). This reproduces the legacy switcher/SEO
 * behaviour where the home context yields a localized-home alternate for every active language.
 */
final class HomeResolver implements LocalizedResourceResolverContract
{
    public function key(): string
    {
        return 'cms.home';
    }

    public function priority(): int
    {
        return 100;
    }

    public function supports(LocalizationContext $context): bool
    {
        return $context->isHome();
    }

    public function descriptorFor(LocalizationContext $context, string $locale): ?RouteDescriptor
    {
        return new RouteDescriptor(
            resolverKey: $this->key(),
            canonicalType: 'home',
            canonicalId: null,
            canonicalPath: '/',
        );
    }
}
