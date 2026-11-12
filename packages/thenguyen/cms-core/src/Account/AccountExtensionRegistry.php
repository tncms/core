<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Account;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TheNguyen\CMS\Http\Middleware\FrontendAuthenticate;
use TheNguyen\CMS\Localization\LocalizedRouteUrl;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use TheNguyen\CMS\Services\ExtensionManager;
use TheNguyen\CMS\Support\Account\AccountNavItem;
use Throwable;

/**
 * Account Extension Registry (`cms.account.extensions`, CORE-P12-2/3).
 *
 * The official way for a plugin to add entries to the account navigation:
 *
 *     app('cms.account.extensions')->registerNavigation([
 *         'id' => 'toplist.articles',                  // {plugin slug}.{name}
 *         'label' => fn () => plugin_trans('toplist', 'account.nav'),
 *         'route' => 'toplist.account.articles',       // the plugin's own GET route behind cms.auth
 *         'active' => ['toplist.account.*'],           // route-name patterns (default: the route)
 *         'icon' => 'file', 'priority' => 60,
 *         'permission' => 'posts.create',              // optional cms_can() ability
 *         'visible' => fn (?User $user): bool => …,     // optional, fails closed
 *         'badge' => fn (?User $user): int|string|null => …,
 *     ]);
 *
 * Ownership: the registering plugin is derived from the call site (the nearest
 * frame under the plugins root, as for trusted scripts), never from the
 * descriptor, and an id must start with that plugin's slug. So a plugin can add
 * entries only in its own namespace; Core entries and other plugins' entries
 * cannot be registered, replaced or removed. The first registration of an id
 * wins. There is no remove/replace API.
 *
 * Entries are validated when registered and resolved per call (locale, user,
 * permission, active state): nothing user- or locale-specific is cached. A bad
 * entry is dropped and recorded in {@see diagnostics()} (no user data, no
 * exception text); every other entry still resolves.
 *
 * Visibility is not authorization: an entry links only to a GET route behind
 * account authentication, and that route keeps enforcing its own access rules.
 */
final class AccountExtensionRegistry
{
    private const ID = '/^([a-z0-9][a-z0-9_-]*)\.([a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*)$/';

    private const ROUTE = '/^[A-Za-z0-9_.-]{1,150}$/';

    private const ROUTE_PATTERN = '/^[A-Za-z0-9_.*-]{1,150}$/';

    private const ICON = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    private const PERMISSION = '/^[A-Za-z0-9_.:-]{1,100}$/';

    private const FIELDS = ['id', 'label', 'route', 'parameters', 'active', 'icon', 'priority', 'permission', 'visible', 'badge'];

    private const MAX_ID = 100;

    private const MAX_LABEL = 120;

    private const MAX_BADGE = 12;

    private const MAX_DIAGNOSTICS = 200;

    /** Middleware that proves a route requires a signed-in account. */
    private const AUTH_MIDDLEWARE = ['cms.auth', 'auth', FrontendAuthenticate::class];

    /** @var array<string, array{owner: string, label: string|Closure, route: string, parameters: array<string, string|int>, active: list<string>, icon: ?string, priority: int, permission: ?string, visible: ?Closure, badge: ?Closure}> */
    private array $entries = [];

    /** @var array<string, array{owner: string, id: string, reason: string}> */
    private array $diagnostics = [];

    public function __construct(
        private readonly ExtensionIdentityResolver $identity,
        private readonly ExtensionManager $extensions,
    ) {}

    /**
     * Register one navigation entry for the calling plugin. Returns false (and
     * records why) when the caller is not an active plugin, the id is not in its
     * namespace or already registered, or the descriptor is invalid.
     *
     * @param  array<string, mixed>  $descriptor
     */
    public function registerNavigation(array $descriptor): bool
    {
        $id = is_string($descriptor['id'] ?? null) ? substr($descriptor['id'], 0, self::MAX_ID + 1) : '';
        $owner = $this->callingPlugin();

        if ($owner === null) {
            return $this->reject('-', $id, 'caller is not an active plugin');
        }

        if (strlen($id) > self::MAX_ID || ! preg_match(self::ID, $id, $match)) {
            return $this->reject($owner, $id, 'invalid id');
        }

        if ($match[1] !== $owner) {
            return $this->reject($owner, $id, 'namespace not owned by the registering plugin');
        }

        if (array_diff(array_keys($descriptor), self::FIELDS) !== []) {
            return $this->reject($owner, $id, 'unknown field');
        }

        if (isset($this->entries[$id])) {
            return $this->reject($owner, $id, 'duplicate id');
        }

        $entry = $this->validated($descriptor);

        if (is_string($entry)) {
            return $this->reject($owner, $id, $entry);
        }

        // An entry links only to the owner's own routes, never to Core's or another plugin's.
        if (! str_starts_with($entry['route'], $owner.'.')) {
            return $this->reject($owner, $id, 'route not owned by the registering plugin');
        }

        $this->entries[$id] = ['owner' => $owner] + $entry;

        return true;
    }

    /**
     * The visible entries for this user and request, as fresh AccountNavItem
     * objects (key = id), ordered by id. Entries of a plugin that is no longer
     * active are skipped (the active list is read once per call).
     *
     * @return list<AccountNavItem>
     */
    public function resolveNavigation(?Authenticatable $user = null, ?Request $request = null): array
    {
        $ids = array_keys($this->entries);
        sort($ids, SORT_STRING);

        $current = $this->currentRouteName($request);
        $active = $ids === [] ? [] : array_flip($this->extensions->activePluginSlugs());
        $items = [];

        // One settings read scope for the batch: the localized-route settings are read
        // (and a missing key is memoized) once, not once per entry.
        app('cms.settings')->withinReadScope(function () use ($ids, $active, $user, $current, &$items): void {
            foreach ($ids as $id) {
                try {
                    $item = isset($active[$this->entries[$id]['owner']])
                        ? $this->resolve($id, $this->entries[$id], $user, $current)
                        : null;
                } catch (Throwable) {
                    $item = $this->skip($this->entries[$id]['owner'], $id, 'entry could not be resolved');
                }

                if ($item !== null) {
                    $items[] = $item;
                }
            }
        });

        return $items;
    }

    /**
     * Every registered id, visible or not: keys a legacy navigation filter item
     * may never take.
     *
     * @return list<string>
     */
    public function reservedKeys(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Rejected registrations and skipped entries of this process: owner, id and
     * a fixed reason. Never user data or exception messages.
     *
     * @return list<array{owner: string, id: string, reason: string}>
     */
    public function diagnostics(): array
    {
        return array_values($this->diagnostics);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{label: string|Closure, route: string, parameters: array<string, string|int>, active: list<string>, icon: ?string, priority: int, permission: ?string, visible: ?Closure, badge: ?Closure}|string
     */
    private function validated(array $d): array|string
    {
        $label = $d['label'] ?? null;

        if (! $label instanceof Closure && $this->cleanLabel($label) === null) {
            return 'invalid label';
        }

        $route = $d['route'] ?? null;

        if (! is_string($route) || ! preg_match(self::ROUTE, $route)) {
            return 'invalid route';
        }

        $parameters = $d['parameters'] ?? [];

        if (! is_array($parameters)) {
            return 'invalid route parameters';
        }

        foreach ($parameters as $key => $value) {
            if (! is_string($key) || ! (is_string($value) || is_int($value))) {
                return 'invalid route parameters';
            }
        }

        $active = $d['active'] ?? [$route];

        if (! is_array($active) || $active === [] || ! array_is_list($active)) {
            return 'invalid active patterns';
        }

        foreach ($active as $pattern) {
            if (! is_string($pattern) || ! preg_match(self::ROUTE_PATTERN, $pattern)) {
                return 'invalid active patterns';
            }
        }

        $icon = $d['icon'] ?? null;

        if ($icon !== null && (! is_string($icon) || ! preg_match(self::ICON, $icon))) {
            return 'invalid icon';
        }

        $priority = $d['priority'] ?? 100;

        if (! is_int($priority) || $priority < 0 || $priority > 10000) {
            return 'invalid priority';
        }

        $permission = $d['permission'] ?? null;

        if ($permission !== null && (! is_string($permission) || ! preg_match(self::PERMISSION, $permission))) {
            return 'invalid permission';
        }

        foreach (['visible', 'badge'] as $callback) {
            if (isset($d[$callback]) && ! $d[$callback] instanceof Closure) {
                return 'invalid '.$callback.' callback';
            }
        }

        return [
            'label' => $label,
            'route' => $route,
            'parameters' => $parameters,
            'active' => $active,
            'icon' => $icon,
            'priority' => $priority,
            'permission' => $permission,
            'visible' => $d['visible'] ?? null,
            'badge' => $d['badge'] ?? null,
        ];
    }

    /** @param  array{owner: string, label: string|Closure, route: string, parameters: array<string, string|int>, active: list<string>, icon: ?string, priority: int, permission: ?string, visible: ?Closure, badge: ?Closure}  $e */
    private function resolve(string $id, array $e, ?Authenticatable $user, string $current): ?AccountNavItem
    {
        if ($e['permission'] !== null && ! cms_can($e['permission'], $user)) {
            return null;
        }

        if ($e['visible'] !== null) {
            try {
                $visible = ($e['visible'])($user) === true;
            } catch (Throwable) {
                return $this->skip($e['owner'], $id, 'visible callback failed');
            }

            if (! $visible) {
                return null;
            }
        }

        $route = Route::getRoutes()->getByName($e['route']);

        if (! $route instanceof RoutingRoute) {
            return $this->skip($e['owner'], $id, 'route not found');
        }

        if (! in_array('GET', $route->methods(), true) || ! $this->requiresAccount($route)) {
            return $this->skip($e['owner'], $id, 'route is not behind account authentication');
        }

        try {
            // Root-relative: never built from the request Host.
            $url = app('cms.localized_route_url')->route($e['route'], $e['parameters'], null, false);
        } catch (Throwable) {
            return $this->skip($e['owner'], $id, 'route url could not be generated');
        }

        try {
            $label = $this->cleanLabel($e['label'] instanceof Closure ? ($e['label'])() : $e['label']);
        } catch (Throwable) {
            return $this->skip($e['owner'], $id, 'label callback failed');
        }

        if ($label === null) {
            return $this->skip($e['owner'], $id, 'invalid label');
        }

        return new AccountNavItem(
            key: $id,
            label: $label,
            url: $url,
            icon: $e['icon'],
            priority: $e['priority'],
            active: $current !== '' && Str::is($e['active'], $current),
            permission: $e['permission'],
            badge: $this->badge($id, $e, $user),
        );
    }

    /** @param  array{owner: string, badge: ?Closure}  $e */
    private function badge(string $id, array $e, ?Authenticatable $user): ?string
    {
        if ($e['badge'] === null) {
            return null;
        }

        try {
            $value = ($e['badge'])($user);
        } catch (Throwable) {
            $this->skip($e['owner'], $id, 'badge callback failed');

            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        if (is_string($value) && ($clean = trim($value)) !== '' && mb_strlen($clean) <= self::MAX_BADGE && ! preg_match('/[\x00-\x1f\x7f]/', $clean)) {
            return $clean;
        }

        $this->skip($e['owner'], $id, 'invalid badge');

        return null;
    }

    private function cleanLabel(mixed $label): ?string
    {
        if (! is_string($label)) {
            return null;
        }

        $label = trim($label);

        return $label === '' || mb_strlen($label) > self::MAX_LABEL || preg_match('/[\x00-\x1f\x7f]/', $label) ? null : $label;
    }

    private function requiresAccount(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (in_array($middleware, self::AUTH_MIDDLEWARE, true) || str_starts_with($middleware, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /** Route name of the current request without the `.localized` twin suffix. */
    private function currentRouteName(?Request $request): string
    {
        try {
            $name = ($request ?? request())->route()?->getName();
        } catch (Throwable) {
            return '';
        }

        if (! is_string($name)) {
            return '';
        }

        return str_ends_with($name, LocalizedRouteUrl::SUFFIX) ? substr($name, 0, -strlen(LocalizedRouteUrl::SUFFIX)) : $name;
    }

    /**
     * Slug of the active plugin whose code made the call, or null. The nearest
     * frame outside Core and vendor (container and helper indirection) decides:
     * when it is not active plugin code — host, theme, compiled view, test, a
     * plugin file reached through a link outside the plugins root, an inactive
     * plugin — the registration is refused, even if some plugin's code is further
     * up the stack (e.g. a theme listener running inside a plugin's hook).
     */
    private function callingPlugin(): ?string
    {
        $skip = [
            $this->normalize(dirname(__DIR__, 2)).'/',          // Core package (src, helpers)
            $this->normalize(base_path('vendor')).'/',           // framework / container indirection
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file)) {
                continue;
            }

            $normalized = $this->normalize($file);

            foreach ($skip as $prefix) {
                if (str_starts_with($normalized, $prefix)) {
                    continue 2;
                }
            }

            return $this->identity->isUnderPluginsRoot($file) ? $this->identity->ownerOfFile($file) : null;
        }

        return null;
    }

    private function normalize(string $file): string
    {
        $file = str_replace('\\', '/', $file);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($file) : $file;
    }

    private function reject(string $owner, string $id, string $reason): bool
    {
        $this->note($owner, $id, $reason);

        return false;
    }

    private function skip(string $owner, string $id, string $reason): null
    {
        $this->note($owner, $id, $reason);

        return null;
    }

    private function note(string $owner, string $id, string $reason): void
    {
        $id = preg_replace('/[^A-Za-z0-9_.-]/', '?', $id) ?? '';
        $key = $owner.'|'.$id.'|'.$reason;

        if (isset($this->diagnostics[$key]) || count($this->diagnostics) >= self::MAX_DIAGNOSTICS) {
            return;
        }

        $this->diagnostics[$key] = ['owner' => $owner, 'id' => $id, 'reason' => $reason];

        try {
            Log::notice("TN CMS account navigation entry [{$id}] of [{$owner}] skipped: {$reason}");
        } catch (Throwable) {
            // Logging must never break the account page.
        }
    }
}
