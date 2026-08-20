<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Services\LanguageManager;

/**
 * CORE-L10N.1B — the single URL builder for the localization platform.
 *
 * It turns a {@see RouteDescriptor} into a prefix-free canonical path, applies the Core prefix
 * policy for a target locale (delegating to the one prefix authority, {@see LanguageManager::localizedUrl()},
 * so output is byte-identical to the legacy path), and makes paths absolute. Every localized URL
 * in the platform — switcher, canonical, hreflang, og:url — flows through here, so there is
 * exactly one URL-generation pipeline. No other component (resolver, strategy, SeoManager,
 * switcher, theme) constructs a localized URL.
 */
final class LocalizedUrlGenerator
{
    public function __construct(private readonly LanguageManager $languages) {}

    /**
     * The prefix-free, root-relative canonical path for a descriptor: its explicit
     * {@see RouteDescriptor::$canonicalPath} when present, otherwise the path built from its
     * named route + parameters. Never carries a locale prefix.
     */
    public function path(RouteDescriptor $descriptor): string
    {
        if ($descriptor->canonicalPath !== null) {
            return $descriptor->canonicalPath;
        }

        return route($descriptor->routeName, $descriptor->routeParameters, false);
    }

    /**
     * Apply the Core prefix policy to a prefix-free path for $locale, returning a root-relative
     * localized path (e.g. '/blog/x' → '/vi/blog/x', or unchanged for the unprefixed default).
     * This is the one place the locale prefix is applied.
     */
    public function localized(string $path, string $locale): string
    {
        return $this->languages->localizedUrl($locale, $path);
    }

    /** Make a root-relative path absolute using the framework URL generator. */
    public function absolute(string $path): string
    {
        return url()->to($path);
    }
}
