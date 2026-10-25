<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use Closure;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use Throwable;

/**
 * Decides whether one component form may submit to its action
 * (CORE-EXTENSION-TRUST S2-R1, ADR S2-R1 §3.3). Returns null when authorized,
 * otherwise a fixed reason code. Any internal error fails closed.
 *
 * The URL is never the authority by itself:
 *   1. the action must be a canonical root-relative path, or the configured
 *      `app.url` plus that path (never the request Host);
 *   2. the router's own match for the effective method identifies the route,
 *      with the same precedence as dispatch (registration order, or the
 *      compiled matcher under `route:cache`); no fallback, no host-bound route
 *      that could win on another host, nothing shared is mutated;
 *   3. the route name + method must be declared by THIS component, its handler
 *      code must be owned by the component's plugin, and a non-GET target must
 *      run CSRF verification;
 *   4. every bound URI parameter must hold a declared literal value;
 *   5. the URL regenerated from that route and those parameters must be
 *      byte-equal to the action, which rejects every encoding, dot-segment,
 *      case or trailing-slash variant the router would also have accepted.
 */
final class TrustedFormTargetAuthorizer
{
    private const RESERVED = ['admin', 'filament', 'install', 'upgrade', 'cms-health'];

    /** @param list<TrustedFormTarget> $targets */
    public function __construct(
        private readonly ExtensionIdentityResolver $identity,
        private readonly string $owner,
        private readonly array $targets,
    ) {}

    public function __invoke(string $action, string $method): ?string
    {
        try {
            return $this->authorize($action, $method);
        } catch (Throwable) {
            return 'form target: unknown route';
        }
    }

    private function authorize(string $action, string $method): ?string
    {
        [$origin, $root, $path, $absolute] = $this->split($action) ?? [null, null, null, null];

        if ($path === null) {
            return 'form target: noncanonical';
        }

        if ($this->reserved($path)) {
            return 'form target: reserved path';
        }

        $request = Request::create($origin.$path, $method);
        $route = $this->match($request);

        if ($route === null) {
            return 'form target: unknown route';
        }

        if ($route->getDomain() !== null || $this->hostRouteShadows($request)) {
            return 'form target: domain route';
        }

        $target = $this->declared($route->getName());

        if ($target === null) {
            return 'form target: undeclared';
        }

        if (! in_array($method, $target->methods, true)) {
            return 'form target: method';
        }

        if ($this->ownerOf($route) !== $this->owner) {
            return 'form target: not owned';
        }

        if ($method !== 'GET' && ! $this->csrfProtected($route, $request)) {
            return 'form target: unprotected';
        }

        $bound = (clone $route)->bind($request);
        $parameters = [];

        foreach ($bound->parameterNames() as $name) {
            $value = $bound->parameter($name);

            if ($value === null) {
                continue;
            }

            if (! is_string($value) || ! in_array($value, $target->parameters[$name] ?? [], true)) {
                return 'form target: parameter';
            }

            $parameters[$name] = $value;
        }

        $canonical = app('url')->toRoute($bound, $parameters, false);

        return ($absolute ? $root.$canonical : $canonical) === $action ? null : 'form target: noncanonical';
    }

    /**
     * [origin, app root, path relative to the app, absolute?] of an action in
     * one of the two accepted shapes, or null. Origin and base path come from
     * `app.url`, never from the request.
     *
     * @return array{0: string, 1: string, 2: string, 3: bool}|null
     */
    private function split(string $action): ?array
    {
        if ($action === '' || ! preg_match('/^[\x21-\x7E]+$/', $action) || strpbrk($action, '?#\\%') !== false) {
            return null;
        }

        $root = rtrim((string) config('app.url'), '/');
        $scheme = parse_url($root, PHP_URL_SCHEME);
        $host = parse_url($root, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $port = parse_url($root, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
        $base = (string) parse_url($root, PHP_URL_PATH);

        if (str_starts_with($action, $root.'/')) {
            [$path, $absolute] = [substr($action, strlen($root)), true];
        } elseif ($base === '' && str_starts_with($action, '/') && ! str_starts_with($action, '//')) {
            [$path, $absolute] = [$action, false];
        } else {
            return null;
        }

        if ($path !== '/' && (str_ends_with($path, '/') || str_contains($path, '//') || preg_match('#/\.\.?(/|$)#', $path))) {
            return null;
        }

        return [$origin, $root, $path, $absolute];
    }

    private function reserved(string $path): bool
    {
        $first = strtolower(explode('/', trim($path, '/'))[0]);
        $admin = strtolower(trim((string) config('cms.admin_path', 'admin'), '/'));

        return in_array($first, [$admin, ...self::RESERVED], true) || str_starts_with($first, 'livewire');
    }

    /** The route the router itself would dispatch (non-fallback only), without binding a shared route. */
    private function match(Request $request): ?Route
    {
        $routes = app('router')->getRoutes();

        if ($routes instanceof CompiledRouteCollection) {
            // Cached routes: the compiled matcher's own precedence (static before
            // dynamic). getByName() builds a fresh Route, so its binding is local.
            try {
                $route = $routes->match($request);
            } catch (HttpExceptionInterface) {
                return null;
            }

            return $route->isFallback ? null : $route;
        }

        // Same order as RouteCollection::matchAgainstRoutes(), minus the bind().
        foreach ($routes->get($request->getMethod()) as $route) {
            if (! $route->isFallback && $route->matches($request)) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Whether a host-bound route matches this method + path. A root-relative
     * action is submitted to whatever host served the page, where that route
     * may win; the configured host alone cannot rule it out.
     */
    private function hostRouteShadows(Request $request): bool
    {
        // UriValidator's path rule, on a local compile (an unmatched route is not compiled yet).
        $path = rawurldecode(rtrim($request->getPathInfo(), '/') ?: '/');

        foreach (app('router')->getRoutes()->get($request->getMethod()) as $route) {
            if ($route->getDomain() === null) {
                continue;
            }

            $compiled = $route->getCompiled() ?? $route->toSymfonyRoute()->compile();

            if (preg_match($compiled->getRegex(), $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function declared(?string $name): ?TrustedFormTarget
    {
        foreach ($this->targets as $target) {
            if ($name !== null && $target->route === $name) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Owning active plugin of the route's handler code, or null. For a
     * controller, the class AND the file declaring the handler method must
     * both belong to it, so an inherited Core/vendor method is not "owned".
     */
    private function ownerOf(Route $route): ?string
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            $file = (new ReflectionFunction($uses))->getFileName();

            return is_string($file) ? $this->identity->ownerOfFile($file) : null;
        }

        if (! is_string($uses) || $uses === '') {
            return null;
        }

        [$class, $method] = array_pad(explode('@', ltrim($uses, '\\'), 2), 2, '__invoke');

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $classFile = (new ReflectionClass($class))->getFileName();
        $methodFile = (new ReflectionMethod($class, $method))->getFileName();

        if (! is_string($classFile) || ! is_string($methodFile)) {
            return null;
        }

        $owner = $this->identity->ownerOfFile($classFile);

        return $owner !== null && $this->identity->ownerOfFile($methodFile) === $owner ? $owner : null;
    }

    private function csrfProtected(Route $route, Request $request): bool
    {
        // The middleware the router will actually run: aliases and groups
        // expanded, exclusions (by alias, group or class) applied.
        $resolved = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
        $csrf = array_filter($resolved, static fn (mixed $m): bool => is_string($m) && is_a(explode(':', $m, 2)[0], VerifyCsrfToken::class, true));

        if ($csrf === []) {
            return false;
        }

        foreach (app(ValidateCsrfToken::class)->getExcludedPaths() as $except) {
            $except = $except !== '/' ? trim((string) $except, '/') : $except;

            if ($request->fullUrlIs($except) || $request->is($except)) {
                return false;
            }
        }

        return true;
    }
}
