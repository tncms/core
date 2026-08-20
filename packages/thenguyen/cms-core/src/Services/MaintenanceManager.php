<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Models\Content;

/**
 * Maintenance Mode Core (v1.0.0-beta.4).
 *
 * Decides whether a public request should see the maintenance page and renders
 * it. This is CMS core, not a plugin: every value lives in cms_settings under
 * the "maintenance.*" group and is read through the (table-guarded) settings
 * manager, so the service never crashes when the settings table is absent.
 *
 * Rendering priority (when enabled and not bypassed):
 *   1. mode=page + a valid published page  → render that page via the theme
 *      (the maintenance check is never re-run, so there is no loop).
 *   2. The active theme provides theme::maintenance → use it.
 *   3. The core fallback view cms.maintenance.
 *   4. A last-resort inline HTML document.
 */
class MaintenanceManager
{
    public const MODE_THEME = 'theme';

    public const MODE_PAGE = 'page';

    public const DEFAULT_TITLE = "We'll be back soon";

    public const DEFAULT_MESSAGE = 'Our website is currently undergoing scheduled maintenance. Please check back later.';

    public const DEFAULT_STATUS = 503;

    public const DEFAULT_RETRY_AFTER = 30;

    /** @var array<int, string> */
    public const DEFAULT_EXCLUDE_PATHS = ['admin', 'login', 'livewire', 'robots.txt', 'sitemap.xml', 'cms-health'];

    // ---------------------------------------------------------------------
    // State
    // ---------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return (bool) settings('maintenance.enabled', false);
    }

    public function mode(): string
    {
        $mode = (string) settings('maintenance.mode', self::MODE_THEME);

        return in_array($mode, [self::MODE_THEME, self::MODE_PAGE], true) ? $mode : self::MODE_THEME;
    }

    /**
     * The HTTP status code for the maintenance response (only 503 or 200).
     */
    public function statusCode(): int
    {
        $code = (int) settings('maintenance.status_code', self::DEFAULT_STATUS);

        return in_array($code, [503, 200], true) ? $code : self::DEFAULT_STATUS;
    }

    /**
     * Retry-After window in minutes, or null when unset / non-positive.
     */
    public function retryAfterMinutes(): ?int
    {
        $value = settings('maintenance.retry_after_minutes', self::DEFAULT_RETRY_AFTER);

        if ($value === null || $value === '') {
            return null;
        }

        $minutes = (int) $value;

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * @return array<int, string>
     */
    public function excludePaths(): array
    {
        $paths = settings('maintenance.exclude_paths', self::DEFAULT_EXCLUDE_PATHS);

        if (! is_array($paths)) {
            return self::DEFAULT_EXCLUDE_PATHS;
        }

        return array_values(array_filter(
            array_map(static fn ($p): string => is_string($p) ? trim($p) : '', $paths),
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * @return array<int, string>
     */
    public function allowedIps(): array
    {
        $ips = settings('maintenance.allowed_ips', []);

        if (! is_array($ips)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($ip): string => is_string($ip) ? trim($ip) : '', $ips),
            static fn (string $ip): bool => $ip !== '',
        ));
    }

    /**
     * A snapshot of the maintenance settings (used by the admin UI / debugging).
     * Never includes anything that must stay private to the response layer.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->mode(),
            'page_id' => (int) settings('maintenance.page_id', 0),
            'title' => (string) settings('maintenance.title', self::DEFAULT_TITLE),
            'message' => (string) settings('maintenance.message', self::DEFAULT_MESSAGE),
            'status_code' => $this->statusCode(),
            'retry_after_minutes' => $this->retryAfterMinutes(),
            'allow_admin_bypass' => (bool) settings('maintenance.allow_admin_bypass', true),
            'allow_logged_in_bypass' => (bool) settings('maintenance.allow_logged_in_bypass', false),
            'allowed_ips' => $this->allowedIps(),
            'exclude_paths' => $this->excludePaths(),
        ];
    }

    // ---------------------------------------------------------------------
    // Decisions
    // ---------------------------------------------------------------------

    /**
     * Whether the given request/user is allowed to bypass maintenance mode.
     * Excluded paths are handled separately via {@see isExcludedPath()}.
     */
    public function shouldBypass(Request $request, ?Authenticatable $user = null): bool
    {
        $user ??= $request->user();

        // Permission bypass: users with system.maintenance.bypass (incl. super
        // admins) when admin bypass is enabled.
        if ((bool) settings('maintenance.allow_admin_bypass', true)
            && $user !== null
            && cms_can('system.maintenance.bypass', $user)) {
            return true;
        }

        // Any authenticated user, when logged-in bypass is enabled.
        if ((bool) settings('maintenance.allow_logged_in_bypass', false) && $user !== null) {
            return true;
        }

        // Allow-listed IP.
        $ip = $request->ip();

        if ($ip !== null && in_array($ip, $this->allowedIps(), true)) {
            return true;
        }

        return false;
    }

    /**
     * Whether a request path should remain accessible during maintenance.
     */
    public function isExcludedPath(string $path): bool
    {
        return self::pathIsExcluded($path, $this->excludePaths());
    }

    /**
     * Pure matcher (testable without the container): exact filenames (entries
     * containing a dot, e.g. robots.txt) match the full path only; bare segments
     * (e.g. admin) match the segment itself and anything beneath it.
     *
     * @param  array<int, string>  $excludedPaths
     */
    public static function pathIsExcluded(string $path, array $excludedPaths): bool
    {
        $path = trim($path, '/');

        foreach ($excludedPaths as $excluded) {
            $excluded = trim((string) $excluded, '/');

            if ($excluded === '') {
                continue;
            }

            if (str_contains($excluded, '.')) {
                if ($path === $excluded) {
                    return true;
                }

                continue;
            }

            if ($path === $excluded || str_starts_with($path, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    /**
     * Build the full maintenance HTTP response: rendered body + status code +
     * Retry-After (503 only) + a noindex robots header so search engines do not
     * index the maintenance page.
     */
    public function response(Request $request): Response
    {
        $statusCode = $this->statusCode();

        $response = response($this->render(), $statusCode);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($statusCode === 503) {
            $retry = $this->retryAfterMinutes();

            if ($retry !== null) {
                $response->headers->set('Retry-After', (string) ($retry * 60));
            }
        }

        return $response;
    }

    /**
     * Render the maintenance body following the rendering priority. Returns an
     * HTML string; {@see response()} wraps it with the status code and headers.
     */
    public function render(): string
    {
        $data = $this->viewData();

        if ($this->mode() === self::MODE_PAGE) {
            $page = $this->renderPage($data);

            if ($page !== null) {
                return $page;
            }
        }

        if (View::exists('theme::maintenance')) {
            return View::make('theme::maintenance', $data)->render();
        }

        if (View::exists('cms.maintenance')) {
            return View::make('cms.maintenance', $data)->render();
        }

        return $this->inlineFallback($data);
    }

    /**
     * Variables shared by every maintenance view.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $statusCode = $this->statusCode();
        $locale = current_locale();

        return [
            'title' => (string) setting_localized('maintenance.title', $locale, self::DEFAULT_TITLE),
            'message' => (string) setting_localized('maintenance.message', $locale, self::DEFAULT_MESSAGE),
            'statusCode' => $statusCode,
            'retryAfterMinutes' => $statusCode === 503 ? $this->retryAfterMinutes() : null,
            'siteName' => (string) setting_localized('general.site_name', $locale, config('cms.name', 'TN CMS')),
            'homeUrl' => url('/'),
        ];
    }

    /**
     * Render the chosen CMS page through the active theme as the maintenance
     * content. Returns null (so the caller falls through) when the page is
     * missing/unpublished or the theme cannot render it. The maintenance check
     * is not re-run here, so there is no infinite loop.
     *
     * @param  array<string, mixed>  $data
     */
    private function renderPage(array $data): ?string
    {
        $id = (int) settings('maintenance.page_id', 0);

        if ($id <= 0) {
            return null;
        }

        $page = Content::query()->where('type', 'page')->whereKey($id)->first();

        if ($page === null || $page->status !== 'published') {
            return null;
        }

        if (! View::exists('theme::pages.page')) {
            return null;
        }

        $locale = app('cms.language')->currentCode();
        $translation = app('cms.content')->getTranslation($page, $locale);

        // Set the page SEO context so the theme's SEO partial has data; the
        // noindex header is added by response() regardless of the view.
        app('cms.seo')->forContent($page, $locale);

        return View::make('theme::pages.page', [
            'content' => $page,
            'title' => $page->translatedTitle($locale),
            'body' => $translation?->content,
            'excerpt' => $translation?->excerpt,
            'featuredImage' => $page->featured_image,
            'publishedAt' => $page->published_at,
        ])->render();
    }

    /**
     * Last-resort inline HTML when neither a theme view nor the core view exist
     * (e.g. no active theme). Clean, responsive, no admin links.
     *
     * @param  array<string, mixed>  $data
     */
    private function inlineFallback(array $data): string
    {
        $site = e((string) $data['siteName']);
        $title = e((string) $data['title']);
        $message = e((string) $data['message']);
        $retry = $data['retryAfterMinutes'];
        $retryHtml = is_int($retry)
            ? '<p style="opacity:.7;font-size:.9rem;">Please check back in about '.(int) $retry.' minutes.</p>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title>{$title} — {$site}</title>
            <style>
                :root { color-scheme: light dark; }
                body {
                    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
                    min-height: 100vh; margin: 0; display: flex; align-items: center;
                    justify-content: center; text-align: center; color: #1f2937; background: #f9fafb;
                }
                @media (prefers-color-scheme: dark) { body { color: #e5e7eb; background: #0b0f19; } }
                .card { max-width: 32rem; padding: 2.5rem 1.5rem; }
                h1 { font-size: 1.6rem; margin: 0 0 .75rem; }
                p { line-height: 1.6; margin: .5rem 0; }
                .site { text-transform: uppercase; letter-spacing: .12em; font-size: .75rem; opacity: .6; margin-bottom: 1.5rem; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="site">{$site}</div>
                <h1>{$title}</h1>
                <p>{$message}</p>
                {$retryHtml}
            </div>
        </body>
        </html>
        HTML;
    }
}
