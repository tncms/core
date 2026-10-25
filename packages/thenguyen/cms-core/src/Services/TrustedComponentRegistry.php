<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Contracts\TrustedComponentProvider;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveHtmlProfile;
use TheNguyen\CMS\Support\TrustedComponents\InteractiveProfileViolation;
use TheNguyen\CMS\Support\TrustedComponents\ReadOnlyRenderGuard;
use TheNguyen\CMS\Support\TrustedComponents\ReadOnlyRenderViolation;
use TheNguyen\CMS\Support\TrustedComponents\TrustedComponentPolicy;
use TheNguyen\CMS\Support\TrustedComponents\TrustedFormTarget;
use TheNguyen\CMS\Support\TrustedComponents\TrustedFormTargetAuthorizer;
use TheNguyen\CMS\Support\TrustedComponents\TrustedRenderContext;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;
use Throwable;

/**
 * Trusted interactive components (CORE-EXTENSION-TRUST S2, ADR D-C, D-6/D-7/D-9/D-10).
 *
 * REGISTRATION authority (boot time, app lifetime):
 *   - the owner is derived from the provider's class file by the shared
 *     {@see ExtensionIdentityResolver}; it must be an active plugin;
 *   - the registering call site (nearest plugin frame) must BE that owner, so
 *     plugin B can never register plugin A's object, even via the container;
 *   - the id is `{owner}/{key}`; duplicates, invalid policies and late
 *     registrations (after the first matched route) are refused.
 *
 * INVOCATION authority (per request), see {@see render()}: the route must run
 * the `cms.trusted_slots` middleware; the owner must still be active; the
 * invoker (nearest plugin frame, or `@host`) must be the owner or allowed by
 * the policy; the mode is server-derived and may only get stricter; props are
 * bounded scalars; the viewer must hold the policy permission (strict). Then
 * the output must pass {@see InteractiveHtmlProfile} as a whole, and every live
 * form must submit to one of the policy's own declared, owner-owned targets
 * ({@see TrustedFormTargetAuthorizer}, S2-R1; default deny).
 *
 * Every refusal returns {@see TrustedSlotVault::FALLBACK} plus a request-scoped
 * diagnostic (component id + fixed reason). Logs never contain props, output
 * or exception messages.
 *
 * Provider objects live for the app lifetime (frozen); everything else —
 * vault, diagnostics, nesting depth — lives on the current Request.
 */
class TrustedComponentRegistry
{
    public const KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,47}$/';

    public const MAX_PROVIDERS = 256;

    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    private const PERMISSION_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,127}$/';

    private const DATA_ATTRIBUTE_PATTERN = '/^data-[a-z][a-z0-9-]{0,40}$/';

    private const MAX_FORM_TARGETS = 16;

    private const DIAGNOSTICS = 'cms.trusted_components.diagnostics';

    private const MAX_DIAGNOSTICS = 100;

    private const MAX_PROPS = 64;

    private const MAX_PROP_DEPTH = 3;

    private const MAX_PROP_STRING = 4096;

    /** Strictness order of render modes. */
    private const MODE_RANK = ['public' => 0, 'preview' => 1, 'editor' => 2];

    /** @var array<string, array{owner: string, key: string, provider: TrustedComponentProvider, policy: TrustedComponentPolicy}> */
    private array $components = [];

    private bool $frozen = false;

    public function __construct(
        private readonly ExtensionIdentityResolver $identity,
        private readonly ReadOnlyRenderGuard $guard,
    ) {}

    // ---------------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------------

    public function register(TrustedComponentProvider $provider): bool
    {
        try {
            if ($this->frozen) {
                return $this->deny('-', 'registry frozen');
            }

            $owner = $this->identity->ownerOf($provider);

            if ($owner === null) {
                return $this->deny('-', 'provider not owned by an active plugin');
            }

            $callSite = $this->callSitePlugin();

            if ($callSite === false) {
                return $this->deny($owner.'/-', 'registration must come from the owner plugin');
            }

            if ($callSite !== $owner) {
                return $this->deny($owner.'/-', 'owner mismatch');
            }

            $key = $provider->key();

            if (! preg_match(self::KEY_PATTERN, $key)) {
                return $this->deny($owner.'/-', 'invalid key');
            }

            $id = $owner.'/'.$key;

            if (isset($this->components[$id])) {
                return $this->deny($id, 'duplicate component');
            }

            if (count($this->components) >= self::MAX_PROVIDERS) {
                return $this->deny($id, 'provider limit');
            }

            $policy = $provider->policy();
            $invalid = $this->invalidPolicy($policy);

            if ($invalid !== null) {
                return $this->deny($id, 'invalid policy: '.$invalid);
            }

            $this->components[$id] = ['owner' => $owner, 'key' => $key, 'provider' => $provider, 'policy' => $policy];

            return true;
        } catch (Throwable) {
            return $this->deny('-', 'registration failed');
        }
    }

    /** Close registration; called on the first matched route of the process. */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function has(string $id): bool
    {
        return isset($this->components[$id]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        $ids = array_keys($this->components);
        sort($ids);

        return $ids;
    }

    // ---------------------------------------------------------------------
    // Invocation
    // ---------------------------------------------------------------------

    /**
     * Render a component for the current request and return its placeholder,
     * or the fallback wrapper on any refusal.
     *
     * @param  array<string, mixed>  $props  untrusted
     */
    public function render(string $component, array $props = [], ?string $mode = null): string
    {
        $label = $this->label($component);

        try {
            $request = request();
            $vault = TrustedSlotVault::forRequest($request);

            if (! $vault->isActive()) {
                return $this->fallback($label, 'no slot resolver on this route');
            }

            if (! $vault->enter()) {
                return $this->fallback($label, 'nested render');
            }

            try {
                return $this->renderInside($request, $vault, $component, $label, $props, $mode);
            } finally {
                $vault->leave();
            }
        } catch (Throwable) {
            return $this->fallback($label, 'render failed');
        }
    }

    /** @param array<string, mixed> $props */
    private function renderInside(Request $request, TrustedSlotVault $vault, string $component, string $label, array $props, ?string $mode): string
    {
        if ($vault->isFull()) {
            return $this->fallback($label, 'slot limit');
        }

        $entry = $this->components[$component] ?? null;

        if ($entry === null) {
            return $this->fallback($label, 'unknown component');
        }

        // Re-derived on every render: deactivation takes effect immediately.
        if ($this->identity->ownerOf($entry['provider']) !== $entry['owner']) {
            return $this->fallback($label, 'owner inactive');
        }

        $policy = $entry['policy'];
        $invoker = $this->invoker();

        if ($invoker === null || ! $this->invokerAllowed($invoker, $entry['owner'], $policy)) {
            return $this->fallback($label, 'invoker not allowed');
        }

        $effective = $this->effectiveMode($request, $mode);

        if ($effective === null) {
            return $this->fallback($label, 'invalid mode');
        }

        if (! in_array($effective, $policy->contexts, true)) {
            return $this->fallback($label, 'context not allowed');
        }

        if (! $this->propsValid($props, 0, $count)) {
            return $this->fallback($label, 'invalid props');
        }

        $user = auth()->user();

        if ($policy->permission !== null && ! app('cms.permission')->userCanStrict($user, $policy->permission)) {
            return $this->fallback($label, 'not authorized');
        }

        $context = new TrustedRenderContext(
            componentId: $component,
            owner: $entry['owner'],
            invoker: $invoker,
            mode: $effective,
            locale: app()->getLocale(),
            routeName: $request->route()?->getName(),
            isAuthenticated: $user !== null,
            userId: is_numeric($user?->getAuthIdentifier()) ? (int) $user->getAuthIdentifier() : null,
        );

        try {
            $render = fn () => $entry['provider']->render($context, $props);
            $html = $context->isReadOnly() ? $this->guard->run($render) : $render();
        } catch (ReadOnlyRenderViolation) {
            return $this->fallback($label, 'read-only violation');
        } catch (Throwable $e) {
            Log::warning('TN CMS trusted component render failed', ['component' => $label, 'exception' => $e::class]);

            return $this->fallback($label, 'render failed', log: false);
        }

        try {
            $safe = $this->profile($request, $context, $policy, $entry['owner'])->validate($html);
        } catch (InteractiveProfileViolation $violation) {
            return $this->fallback($label, 'profile violation: '.$violation->reason);
        }

        return $vault->mint($safe);
    }

    private function profile(Request $request, TrustedRenderContext $context, TrustedComponentPolicy $policy, string $owner): InteractiveHtmlProfile
    {
        return new InteractiveHtmlProfile(
            origin: $request->getSchemeAndHttpHost(),
            readOnly: $context->isReadOnly(),
            dataAttributes: $policy->dataAttributes,
            formAuthorizer: (new TrustedFormTargetAuthorizer($this->identity, $owner, $policy->formTargets))(...),
        );
    }

    /**
     * Request-scoped refusals: component id + fixed reason, nothing else.
     *
     * @return list<array{component: string, reason: string}>
     */
    public function diagnostics(): array
    {
        try {
            $diagnostics = request()->attributes->get(self::DIAGNOSTICS, []);

            return is_array($diagnostics) ? $diagnostics : [];
        } catch (Throwable) {
            return [];
        }
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    private function invalidPolicy(TrustedComponentPolicy $policy): ?string
    {
        $contexts = $policy->contexts;

        if ($contexts === [] || ! array_is_list($contexts) || array_diff($contexts, TrustedComponentPolicy::MODES) !== []) {
            return 'contexts';
        }

        foreach ($policy->invokers as $invoker) {
            if (! is_string($invoker) || ($invoker !== TrustedComponentPolicy::HOST && ! preg_match(self::SLUG_PATTERN, $invoker))) {
                return 'invokers';
            }
        }

        if ($policy->permission !== null && ! preg_match(self::PERMISSION_PATTERN, $policy->permission)) {
            return 'permission';
        }

        foreach ($policy->dataAttributes as $name) {
            if (! is_string($name) || ! preg_match(self::DATA_ATTRIBUTE_PATTERN, $name) || str_starts_with($name, 'data-tncms')) {
                return 'data attributes';
            }
        }

        return $this->formTargetsValid($policy->formTargets) ? null : 'form targets';
    }

    /** At most 16 valid targets, each route declared once. Routes are resolved per render. */
    private function formTargetsValid(array $targets): bool
    {
        if (! array_is_list($targets) || count($targets) > self::MAX_FORM_TARGETS) {
            return false;
        }

        $routes = [];

        foreach ($targets as $target) {
            if (! $target instanceof TrustedFormTarget || ! $target->isValid() || isset($routes[$target->route])) {
                return false;
            }

            $routes[$target->route] = true;
        }

        return true;
    }

    /** The requesting plugin slug, `@host` (no plugin frame), or null (inactive plugin frame). */
    private function invoker(): ?string
    {
        $callSite = $this->callSitePlugin();

        if ($callSite === false) {
            return TrustedComponentPolicy::HOST;
        }

        return $callSite === '' ? null : $callSite;
    }

    private function invokerAllowed(string $invoker, string $owner, TrustedComponentPolicy $policy): bool
    {
        return $invoker === $owner || in_array($invoker, $policy->invokers, true);
    }

    /** Server mode (signed preview → preview), made only stricter by a request. */
    private function effectiveMode(Request $request, ?string $requested): ?string
    {
        $server = $request->attributes->get('cms_preview') === true ? 'preview' : 'public';

        if ($requested === null) {
            return $server;
        }

        if (! isset(self::MODE_RANK[$requested])) {
            return null;
        }

        return self::MODE_RANK[$requested] > self::MODE_RANK[$server] ? $requested : $server;
    }

    private function propsValid(mixed $value, int $depth, ?int &$count): bool
    {
        $count ??= 0;

        if (! is_array($value)) {
            return $value === null || is_bool($value) || is_int($value) || is_float($value)
                || (is_string($value) && strlen($value) <= self::MAX_PROP_STRING);
        }

        if ($depth > self::MAX_PROP_DEPTH) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (++$count > self::MAX_PROPS) {
                return false;
            }

            if (is_string($key) && ! preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)) {
                return false;
            }

            if (! $this->propsValid($item, $depth + 1, $count)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Owner of the nearest plugin frame on the stack: a slug, '' for a frame in
     * an inactive/untrusted plugin directory, or false when no plugin frame
     * exists (Core, theme, host or test code). Same rule as S1's enqueue().
     */
    private function callSitePlugin(): string|false
    {
        $skip = [
            $this->normalize(__FILE__),
            $this->normalize(dirname(__DIR__).DIRECTORY_SEPARATOR.'helpers.php'),
        ];

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || in_array($this->normalize($file), $skip, true)) {
                continue;
            }

            if ($this->identity->isUnderPluginsRoot($file)) {
                return $this->identity->ownerOfFile($file) ?? '';
            }
        }

        return false;
    }

    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function fallback(string $label, string $reason, bool $log = true): string
    {
        $this->note($label, $reason);

        if ($log) {
            try {
                Log::debug('TN CMS trusted component refused', ['component' => $label, 'reason' => $reason]);
            } catch (Throwable) {
                // Logging is best-effort.
            }
        }

        return TrustedSlotVault::FALLBACK;
    }

    private function deny(string $label, string $reason): bool
    {
        $this->note($label, $reason);

        return false;
    }

    private function note(string $label, string $reason): void
    {
        try {
            $diagnostics = $this->diagnostics();

            if (count($diagnostics) < self::MAX_DIAGNOSTICS) {
                $diagnostics[] = ['component' => $label, 'reason' => $reason];
                request()->attributes->set(self::DIAGNOSTICS, $diagnostics);
            }
        } catch (Throwable) {
            // Diagnostics are best-effort.
        }
    }

    /** A component id safe to log/diagnose: bounded and charset-restricted. */
    private function label(string $value): string
    {
        $clean = preg_replace('/[^a-z0-9._\/-]/i', '?', $value) ?? '?';

        return substr($clean, 0, 113);
    }
}
