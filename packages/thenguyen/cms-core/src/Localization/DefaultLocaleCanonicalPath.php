<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use TheNguyen\CMS\Localization\Dictionary\RouteSegmentDictionary;
use TheNguyen\CMS\Services\LanguageManager;

/**
 * Canonical default-locale paths for localized base spellings
 * (CORE-PLATFORM-SEAMS-R2).
 *
 * Core's contract: the default locale renders the canonical route key
 * unprefixed (PlatformRouteDictionary; content/term/commerce resolvers). A site
 * whose default locale ALSO has a dictionary projection (vi: search → tim-kiem,
 * category → danh-muc) can still receive the projected spelling (/tim-kiem,
 * /vi/tim-kiem). This maps it back to the canonical key path through the ONE
 * dictionary — never a hard-coded segment — and only when that path is served
 * by a real route (never the catch-all), so an unrelated page or a renamed
 * base is never redirected into a 404.
 */
final class DefaultLocaleCanonicalPath
{
    /** The generic resolvers are never a canonical target. */
    private const CATCH_ALL = ['cms.resolve', 'cms.resolve.localized'];

    public function __construct(
        private readonly RouteSegmentDictionary $dictionary,
        private readonly LanguageManager $languages,
        private readonly Router $router,
    ) {}

    /**
     * Root-relative canonical path (query appended) for unprefixed default-locale
     * $segments whose first segment is a default-locale projection of another
     * route key, or null when nothing should change.
     *
     * @param  list<string>  $segments
     */
    public function forSegments(array $segments, ?string $query): ?string
    {
        $first = $segments[0] ?? null;

        if (! is_string($first) || $first === '') {
            return null;
        }

        $key = $this->dictionary->keyFor($first, $this->languages->defaultCode());

        if ($key === null || $key->value === $first) {
            return null;
        }

        $segments[0] = $key->value;
        $path = '/'.implode('/', array_map(static fn (string $s): string => rawurlencode($s), $segments));

        if (! $this->servedByARealRoute($path)) {
            return null;
        }

        return $query !== null && $query !== '' ? $path.'?'.$query : $path;
    }

    private function servedByARealRoute(string $path): bool
    {
        try {
            $route = $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        return ! in_array($route->getName(), self::CATCH_ALL, true);
    }
}
