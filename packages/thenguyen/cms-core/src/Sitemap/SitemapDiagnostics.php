<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Sitemap;

use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only sitemap diagnostics (CORE-SITEMAP-ADMIN-R1), bound as
 * `cms.sitemap_diagnostics`.
 *
 * Everything runs in-process through the same services that serve the
 * public endpoints: no outbound HTTP (so no SSRF and no Host trust), at most
 * ONE child sitemap rendered per provider, a wall-clock budget, and one run at
 * a time. Reports hold statuses, counts and exception class names only —
 * never messages, traces, settings values or private URLs.
 */
final class SitemapDiagnostics
{
    public const BUDGET_SECONDS = 20;

    public const MAX_PROVIDERS = 50;

    private const LAST = 'cms.sitemap.diagnostics.last';

    private const CORE_ROUTES = ['cms.robots', 'cms.sitemap', 'cms.sitemap.chunk'];

    /** @return array<string, mixed>|null the last stored report */
    public function last(): ?array
    {
        try {
            $report = Cache::store()->get(self::LAST);
        } catch (Throwable) {
            return null;
        }

        return is_array($report) ? $report : null;
    }

    /**
     * @return array{ran_at: int, user_id: int|string|null, status: string, partial: bool, duration_ms: int, checks: list<array{key: string, status: string, detail: string}>, providers: list<array<string, mixed>>, duplicates: list<string>}
     */
    public function run(): array
    {
        // Released in finally; the TTL only guards a crashed worker, so it is
        // generous enough that a slow run cannot outlive it and overlap.
        $lock = Cache::lock('cms.sitemap.diagnostics.run', self::BUDGET_SECONDS * 6);

        if (! $lock->get()) {
            return $this->report(time(), 'busy', false, 0, [], [], []);
        }

        try {
            return $this->execute();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function execute(): array
    {
        $started = microtime(true);
        $ranAt = time();
        $deadline = $started + self::BUDGET_SECONDS;
        $manager = app('cms.sitemap');
        $enabled = $manager->enabled();

        $checks = [
            $this->indexingPolicy(),
            $this->cacheHealth(),
            $this->reservedRoutes(),
            $this->robots($enabled),
            $this->index($enabled),
        ];

        $providers = [];
        $seen = [];
        $duplicates = [];
        $partial = false;
        $rows = array_slice(app('cms.sitemap_settings')->providerRows(), 0, self::MAX_PROVIDERS);

        // One parts() walk per run (it covers every provider), grouped by provider.
        $byProvider = [];
        $partsError = null;
        if ($enabled) {
            try {
                foreach ($manager->parts() as $part) {
                    $byProvider[$part['provider']][] = $part;
                }
            } catch (Throwable $e) {
                $partsError = self::name($e);
            }
        }

        foreach ($rows as $row) {
            if (microtime(true) > $deadline) {
                $partial = true;
                $providers[] = ['id' => $row['id'], 'status' => 'skipped', 'detail' => 'time budget reached'];

                continue;
            }

            $result = $this->provider($row, $enabled, $byProvider[$row['id']] ?? [], $partsError);

            foreach ($result['locs'] ?? [] as $loc) {
                if (isset($seen[$loc]) && $seen[$loc] !== $row['id']) {
                    $duplicates[$loc] = true;
                }
                $seen[$loc] ??= $row['id'];
            }

            unset($result['locs']);
            $providers[] = $result;
        }

        $statuses = [...array_column($checks, 'status'), ...array_column($providers, 'status')];
        $overall = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) || $partial ? 'warn' : 'pass');

        $report = $this->report(
            $ranAt,
            $overall,
            $partial,
            (int) round((microtime(true) - $started) * 1000),
            $checks,
            $providers,
            array_slice(array_keys($duplicates), 0, 20),
        );

        try {
            Cache::store()->forever(self::LAST, $report);
        } catch (Throwable $e) {
            Log::warning('TN CMS sitemap diagnostics not stored', ['exception' => $e::class]);
        }

        Log::info('TN CMS sitemap diagnostics run', ['user_id' => $report['user_id'], 'status' => $overall]);

        return $report;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array<string, mixed>>  $providers
     * @param  list<string>  $duplicates
     * @return array<string, mixed>
     */
    private function report(int $ranAt, string $status, bool $partial, int $ms, array $checks, array $providers, array $duplicates): array
    {
        return [
            'ran_at' => $ranAt,
            'user_id' => auth()->id(),
            'status' => $status,
            'partial' => $partial,
            'duration_ms' => $ms,
            'checks' => $checks,
            'providers' => $providers,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * `detail` is an English tn_trans() template; `params` fill its :placeholders
     * so the Admin view can render it in the operator's language.
     *
     * @param  array<string, string|int>  $params
     * @return array{key: string, status: string, detail: string, params: array<string, string|int>}
     */
    private function check(string $key, string $status, string $detail, array $params = []): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail, 'params' => $params];
    }

    private function indexingPolicy(): array
    {
        return app('cms.seo')->siteIndexable()
            ? $this->check('indexing_policy', 'pass', 'site indexable')
            : $this->check('indexing_policy', 'warn', 'site discourages indexing: the sitemap is not served');
    }

    private function cacheHealth(): array
    {
        $key = 'cms.sitemap.diagnostics.probe';

        try {
            $token = bin2hex(random_bytes(8));
            Cache::store()->put($key, $token, 60);
            $ok = Cache::store()->get($key) === $token;
            Cache::store()->forget($key);
        } catch (Throwable $e) {
            return $this->check('cache', 'fail', ':error', ['error' => self::name($e)]);
        }

        return $ok ? $this->check('cache', 'pass', 'read/write ok') : $this->check('cache', 'fail', 'value not read back');
    }

    private function reservedRoutes(): array
    {
        $conflicts = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (ReservedSeoPaths::claims($route->uri()) && ! in_array($route->getName(), self::CORE_ROUTES, true)) {
                $conflicts[] = '/'.ltrim($route->uri(), '/');
            }
        }

        foreach (self::CORE_ROUTES as $name) {
            if (! app('router')->has($name)) {
                return $this->check('reserved_routes', 'fail', 'missing Core route :name', ['name' => $name]);
            }
        }

        return $conflicts === []
            ? $this->check('reserved_routes', 'pass', 'no route claims a reserved path')
            : $this->check('reserved_routes', 'fail', 'conflicts: :list', ['list' => implode(', ', array_slice(array_unique($conflicts), 0, 10))]);
    }

    private function robots(bool $enabled): array
    {
        try {
            $advertises = str_contains(app('cms.seo')->robotsTxt(), "\nSitemap: ");
        } catch (Throwable $e) {
            return $this->check('robots', 'fail', ':error', ['error' => self::name($e)]);
        }

        return $advertises === $enabled
            ? $this->check('robots', 'pass', $enabled ? 'advertises the index' : 'does not advertise a disabled sitemap')
            : $this->check('robots', 'fail', 'robots.txt disagrees with the sitemap state');
    }

    private function index(bool $enabled): array
    {
        if (! $enabled) {
            return $this->check('index', 'skip', 'sitemap disabled');
        }

        try {
            $doc = $this->parse(app('cms.sitemap')->indexXml(), 'sitemapindex');
        } catch (SitemapUnavailableException) {
            return $this->check('index', 'fail', 'index unavailable (503)');
        } catch (Throwable $e) {
            return $this->check('index', 'fail', ':error', ['error' => self::name($e)]);
        }

        return $doc === null
            ? $this->check('index', 'fail', 'invalid XML')
            : $this->check('index', 'pass', ':n child sitemaps', ['n' => $doc->getElementsByTagName('sitemap')->length]);
    }

    /**
     * One provider: its manifest (bounded keyset boundaries) and at most one
     * rendered child, validated as XML.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $parts  this provider's parts from the run's single parts() walk
     * @return array<string, mixed>
     */
    private function provider(array $row, bool $enabled, array $parts, ?string $partsError): array
    {
        $out = ['id' => $row['id'], 'status' => 'pass', 'detail' => '', 'params' => [], 'children' => 0, 'sampled_children' => 0, 'sampled_urls' => 0];

        if (! $row['registered']) {
            return ['status' => 'warn', 'detail' => 'not registered (plugin inactive or removed)'] + $out;
        }

        if (! $row['included']) {
            return ['status' => 'skip', 'detail' => 'excluded by the administrator'] + $out;
        }

        if (! $enabled) {
            return ['status' => 'skip', 'detail' => 'sitemap disabled'] + $out;
        }

        $manager = app('cms.sitemap');

        if ($partsError !== null) {
            return ['status' => 'fail', 'detail' => ':error', 'params' => ['error' => $partsError]] + $out;
        }

        $out['children'] = count($parts);
        $status = $manager->status($row['id']);

        // Latest outcome wins: a degraded manifest, or a failure newer than the last good build.
        if (($status['degraded'] ?? false) === true || ($status['failed_at'] ?? 0) > ($status['built_at'] ?? 0)) {
            $out['status'] = 'fail';
            $out['detail'] = 'manifest degraded: :error';
            $out['params'] = ['error' => (string) ($status['error'] ?? 'error')];
        }

        if ($parts === []) {
            return $out['status'] === 'pass' ? ['detail' => 'no eligible URLs'] + $out : $out;
        }

        try {
            $stream = $manager->chunkStream($parts[0]);
            $xml = $stream === null ? '' : (string) stream_get_contents($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $doc = $this->parse($xml, 'urlset');
        } catch (Throwable $e) {
            return ['status' => 'fail', 'detail' => 'child failed: :error', 'params' => ['error' => self::name($e)], 'sampled_children' => 1] + $out;
        }

        if ($doc === null) {
            return ['status' => 'fail', 'detail' => 'child is not valid sitemap XML', 'sampled_children' => 1] + $out;
        }

        $locs = [];
        foreach ($doc->getElementsByTagName('loc') as $node) {
            $locs[] = $node->textContent;
        }

        return ['sampled_children' => 1, 'sampled_urls' => count($locs), 'locs' => $locs] + $out;
    }

    private function parse(string $xml, string $root): ?DOMDocument
    {
        if ($xml === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // Our own output never carries a DOCTYPE; refuse one outright (no entity surface).
        return $ok && $doc->doctype === null && $doc->documentElement?->localName === $root
            && $doc->documentElement->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9'
            ? $doc
            : null;
    }

    private static function name(Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName();
    }
}
