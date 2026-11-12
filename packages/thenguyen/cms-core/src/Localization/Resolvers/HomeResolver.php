<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Resolvers;

use TheNguyen\CMS\Content\Extension\FrontPage;
use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\LocalizationContext;
use TheNguyen\CMS\Localization\RouteDescriptor;

/**
 * CORE-L10N.1B — the built-in Home resolver.
 *
 * The site root has a target in every enabled locale (the localized home path), so it produces a
 * descriptor for the home path ('/'). This reproduces the legacy switcher/SEO behaviour where the
 * home context yields a localized-home alternate for every active language — except a locale in
 * which a configured static homepage is untranslated (owner policy A: that `/` is a 404).
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
        // Owner policy A (CORE-RELEASE-BLOCKERS-R1-CLOSURE): a configured static homepage with no
        // translation in this locale is a 404 there, so that locale has no home target (no
        // hreflang alternate; the switcher falls back to its own localized-home handling).
        if (FrontPage::staticHomeUnavailableIn($locale)) {
            return null;
        }

        return new RouteDescriptor(
            resolverKey: $this->key(),
            canonicalType: 'home',
            canonicalId: null,
            canonicalPath: '/',
        );
    }
}
