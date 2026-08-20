<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-L10N.1B (Phase P3.3) — the ONE builder of a {@see LocalizationContext}
 * from the current-resource authority.
 *
 * Language switcher, SEO alternates, and any future consumer build their
 * LocalizationContext through here rather than each assembling a subtly
 * different one from SeoManager state. The factory reads only
 * {@see CurrentResourceContext}; it resolves no translations, generates no URL,
 * sets no SEO metadata, mutates no locale, and knows no plugin classes.
 *
 * Home ('home') and an unset context ('default') both produce a resource-less
 * LocalizationContext — byte-identical to the legacy behaviour where SeoManager
 * reported 'home' / 'default'. A backing Content/Term is threaded into the
 * legacy content/term slots so Core resolvers keep matching unchanged; a plugin
 * resource is carried in the reference and matched on type alone.
 */
final class CurrentLocalizationContextFactory
{
    public function __construct(private readonly CurrentResourceContext $context) {}

    public function current(): LocalizationContext
    {
        $reference = $this->context->current();

        if ($reference === null) {
            return new LocalizationContext('default');
        }

        if ($reference->isHome()) {
            return new LocalizationContext('home', null, null, $reference);
        }

        $content = $reference->resource instanceof Content ? $reference->resource : null;
        $term = $reference->resource instanceof Term ? $reference->resource : null;

        return new LocalizationContext($reference->type, $content, $term, $reference);
    }
}
