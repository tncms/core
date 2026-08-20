<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Services\PublicContentCacheManager;

/**
 * Per-request query budget + opt-in query profiler for public pages
 * (v1.0.0-beta.6.4.2).
 *
 * - Budget: counts DB queries for the request and, if the page type's budget is
 *   exceeded, report()s it. It NEVER alters or fails the response — a slow page
 *   still renders; the overage just surfaces in logs/monitoring.
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
     * report() when the query count exceeds the page type's budget. Never throws.
     */
    private function enforceBudget(string $type, int $queries, Request $request): void
    {
        $budgets = (array) config('cms.performance.query_budget.budgets', []);
        $budget = (int) ($budgets[$type] ?? $budgets['default'] ?? 0);

        if ($budget <= 0 || $queries <= $budget) {
            return;
        }

        report(new \RuntimeException(sprintf(
            'TN CMS query budget exceeded: %d queries on a "%s" page (budget %d) at %s',
            $queries,
            $type,
            $budget,
            $request->path(),
        )));
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
