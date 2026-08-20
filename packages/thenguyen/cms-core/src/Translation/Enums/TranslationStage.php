<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Enums;

/**
 * The point in the fallback chain at which a translation was resolved.
 *
 * Ordered from most to least specific: a resolver walks the configured chain
 * (see config('translation.fallback_chain')) and stamps the result with the
 * stage that produced the value. {@see Miss} means nothing matched.
 */
enum TranslationStage: string
{
    case Requested = 'requested';
    case SiteFallback = 'site_fallback';
    case DefaultLocale = 'default';
    case Raw = 'raw';
    case Miss = 'miss';
}
