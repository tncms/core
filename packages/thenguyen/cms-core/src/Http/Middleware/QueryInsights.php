<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * Per-request query budget + opt-in query profiler for public pages
 * (v1.0.0-beta.6.4.2).
 *
 * - Budget: counts DB queries for the request and, if the page type's budget is
 *   exceeded, logs a structured warning — at most once per page type + path per
 *   REPORT_WINDOW_SECONDS, never as an exception report. It NEVER alters or fails
 *   the response — a slow page still renders; the overage just surfaces in logs.
 * - Profiler: only when CMS_DEBUG_QUERIES=true, adds X-TNCMS-Queries,
 *   X-TNCMS-Time and X-TNCMS-Cache headers for diagnostics.
 *
 * When both are disabled the middleware is a no-op (no listener, no timing).
 */
class QueryInsights
{
    /** Map of public route names → budget/page-type key. */
    private const ROUTE_TYPES = [
        'cms.home' => 'homepage',
        'cms.page' => 'content',
        'cms.post' => 'content',
        'cms.category' => 'category',
        'cms.tag' => 'tag',
    ];

    /** One warning per page type + path per window, so a hot over-budget page cannot flood the log. */
    private const REPORT_WINDOW_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $budgetEnabled = (bool) config('cms.performance.query_budget.enabled', true);
        $profilerEnabled = (bool) config('cms.performance.profiler.enabled', false);

        if (! $budgetEnabled && ! $profilerEnabled) {
            return $next($request);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            ++$queries;
        });

        $start = microtime(true);

        $response = $next($request);

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $type = $this->pageType($request);

        if ($budgetEnabled) {
            $this->enforceBudget($type, $queries, $request);
        }

        if ($profilerEnabled) {
            $this->addHeaders($response, $queries, $elapsedMs);
        }

        return $response;
    }

    /**
     * Resolve the page type: the controller-set request attribute wins (it knows
     * what a catch-all slug resolved to), then the route name, then 'default'.
     */
    private function pageType(Request $request): string
    {
        $tagged = $request->attributes->get('cms.page_type');

        if (is_string($tagged) && $tagged !== '') {
            return $tagged;
        }

        $name = $request->route()?->getName();

        if (is_string($name)) {
            $name = preg_replace('/\.localized$/', '', $name) ?? $name;

            if (isset(self::ROUTE_TYPES[$name])) {
                return self::ROUTE_TYPES[$name];
            }
        }

        return 'default';
    }

    /**
     * Log a bounded warning when the query count exceeds the page type's budget. Never throws.
     */
    private function enforceBudget(string $type, int $queries, Request $request): void
    {
        $budgets = (array) config('cms.performance.query_budget.budgets', []);
        $budget = (int) ($budgets[$type] ?? $budgets['default'] ?? 0);

        if ($budget <= 0 || $queries <= $budget) {
            return;
        }

        $path = $request->path();

        try {
            if (! Cache::add('cms:query-budget:'.sha1($type.'|'.$path), 1, self::REPORT_WINDOW_SECONDS)) {
                return;
            }

            Log::warning('TN CMS query budget exceeded.', [
                'page_type' => $type,
                'queries' => $queries,
                'budget' => $budget,
                'path' => $path,
            ]);
        } catch (\Throwable) {
            // Diagnostics only: a cache or log failure must never affect the response.
        }
    }

    private function addHeaders(Response $response, int $queries, int $elapsedMs): void
    {
        $response->headers->set('X-TNCMS-Queries', (string) $queries);
        $response->headers->set('X-TNCMS-Time', $elapsedMs.'ms');
        $response->headers->set('X-TNCMS-Cache', $this->cacheState());
    }

    private function cacheState(): string
    {
        try {
            $state = app(PublicContentCacheManager::class)->lastCacheState();
        } catch (\Throwable) {
            $state = null;
        }

        return $state ?? 'NONE';
    }
}
