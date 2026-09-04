<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Brands public frontend HTML error pages through the active theme with a
 * recursion-safe Core fallback (CORE-FRONTEND-1).
 *
 * Registered as a render callback in bootstrap/app.php's withExceptions(). The
 * responder is deliberately conservative: it only themes a curated set of HTTP
 * statuses for genuine public frontend HTML requests, and DEFERS everything
 * else (API/JSON, admin, installer, upgrade, Livewire, auth/validation flows,
 * and — in debug — server errors) back to the framework by returning null.
 *
 * Rendering order for a themed status:
 *   1. theme::errors.{status}  (theme specialized)
 *   2. theme::errors.error     (theme generic)
 *   3. errors.cms-{status}     (Core self-contained, no theme dependency)
 *   4. errors.cms-error        (Core self-contained generic)
 *
 * Theme views extend the theme layout, so a broken theme could throw while
 * rendering the error page; each theme attempt is guarded and falls through to
 * the Core self-contained fallback, which never loads the theme. If even that
 * fails, the whole responder returns null so the framework's own safe handler
 * takes over — an error page can never recurse into the failure it is reporting.
 */
final class FrontendErrorResponder
{
    /** HTTP statuses branded for public frontend HTML requests. */
    private const THEMED_STATUSES = [403, 404, 419, 429, 500, 503];

    public function render(Throwable $e, Request $request): ?Response
    {
        try {
            if (! $this->handlesRequest($request)) {
                return null;
            }

            $status = $this->statusFor($e);

            if ($status === null || ! in_array($status, self::THEMED_STATUSES, true)) {
                return null;
            }

            // In debug, let the framework surface its rich diagnostics for server
            // errors so developers keep the stack trace and exception detail.
            if ($status >= 500 && (bool) config('app.debug')) {
                return null;
            }

            return $this->renderStatus($status, $e, $request);
        } catch (Throwable) {
            // Never let error rendering recurse or mask the original failure —
            // defer to the framework's own safe handler.
            return null;
        }
    }

    /**
     * Only public frontend HTML requests are themed. Everything that owns its own
     * response format — JSON/AJAX, admin, installer, upgrade, Livewire, the API —
     * is deferred so its expected contract (Filament pages, JSON error bodies,
     * Livewire protocol frames) is never hijacked.
     */
    private function handlesRequest(Request $request): bool
    {
        if ($request->expectsJson() || $request->isJson()) {
            return false;
        }

        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        $adminPath = trim((string) config('cms.admin_path', 'admin'), '/');

        if ($adminPath !== '' && $request->is($adminPath, $adminPath.'/*')) {
            return false;
        }

        return ! $request->is(
            'install', 'install/*',
            'upgrade', 'upgrade/*',
            'livewire', 'livewire/*',
            'api', 'api/*',
        );
    }

    /**
     * Map a throwable to the HTTP status to brand, or null to defer. Framework
     * flows with their own handling (authentication redirect/401, validation 422)
     * return null; genuinely unexpected throwables brand as a 500.
     */
    private function statusFor(Throwable $e): ?int
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        if ($e instanceof TokenMismatchException) {
            return 419;
        }

        if ($e instanceof ModelNotFoundException) {
            return 404;
        }

        if ($e instanceof AuthorizationException) {
            return 403;
        }

        // Login redirect / 401 JSON and form validation keep their framework
        // behaviour — they are not error pages.
        if ($e instanceof AuthenticationException || $e instanceof ValidationException) {
            return null;
        }

        return 500;
    }

    private function renderStatus(int $status, Throwable $e, Request $request): Response
    {
        $data = $this->errorData($status, $request);

        // Preserve response headers the framework attaches to the exception
        // (notably Retry-After for 429 rate limiting).
        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        // 1) Theme-branded views (specialized then generic), each recursion-guarded.
        foreach (["theme::errors.{$status}", 'theme::errors.error'] as $themeView) {
            if (! View::exists($themeView)) {
                continue;
            }

            try {
                // Themed pages extend the theme layout, whose SEO partial reads the
                // SeoManager — mark the context noindex,follow before rendering.
                seo()->forCustom($data['title'], '')->noindex();

                $html = View::make($themeView, $data)->render();

                return response($html, $status, $headers);
            } catch (Throwable) {
                // A broken/looping theme view falls through to the Core fallback.
            }
        }

        // 2) Core self-contained fallback — never loads the theme, always renderable.
        $coreView = View::exists("errors.cms-{$status}") ? "errors.cms-{$status}" : 'errors.cms-error';

        return response(View::make($coreView, $data)->render(), $status, $headers);
    }

    /**
     * The sanitized, localized presentation contract passed to every error view.
     * It exposes only safe fields — status, title, message and canonical action
     * URLs — and never the throwable, stack trace, paths, SQL or env detail.
     *
     * @return array{status: int, title: string, message: string, brand: string, homeUrl: string, searchUrl: string|null, homeLabel: string, searchLabel: string}
     */
    private function errorData(int $status, Request $request): array
    {
        $searchUrl = Route::has('cms.search') ? route('cms.search') : null;

        return [
            'status' => $status,
            'title' => $this->title($status),
            'message' => $this->message($status),
            'brand' => $this->brand(),
            'homeUrl' => $this->homeUrl(),
            'searchUrl' => $searchUrl,
            'homeLabel' => core_trans('Go to homepage'),
            'searchLabel' => core_trans('Search this site'),
        ];
    }

    private function title(int $status): string
    {
        return core_trans(match ($status) {
            403 => 'Access denied',
            404 => 'Page not found',
            419 => 'Page expired',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => 'Server error',
        });
    }

    private function message(int $status): string
    {
        return core_trans(match ($status) {
            403 => 'You do not have permission to view this page.',
            404 => 'The page you are looking for does not exist or has moved.',
            419 => 'Your session has expired. Please refresh the page and try again.',
            429 => 'You have made too many requests. Please slow down and try again shortly.',
            503 => 'The site is temporarily unavailable. Please check back soon.',
            default => 'Something went wrong on our end. Please try again later.',
        });
    }

    private function brand(): string
    {
        $name = settings('general.site_name');

        return is_string($name) && trim($name) !== '' ? $name : 'TN CMS';
    }

    private function homeUrl(): string
    {
        try {
            return Route::has('cms.home') ? route('cms.home') : url('/');
        } catch (Throwable) {
            return url('/');
        }
    }
}
