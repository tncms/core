<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use TheNguyen\CMS\Support\Assets\Asset;
use TheNguyen\CMS\Support\Plugin;
use TheNguyen\CMS\Support\TrustedScripts\CspRequirements;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptDeclaration;
use TheNguyen\CMS\Support\TrustedScripts\TrustedScriptManifestParser;
use Throwable;

/**
 * Trusted External Script Registry (CORE-EXTENSION-TRUST S1, `cms.trusted_scripts`).
 *
 * Lets an ACTIVE plugin load a reviewed third-party script (e.g. a CAPTCHA
 * widget) on the requests that need it, without widening any global host
 * allowlist:
 *
 *  1. The plugin DECLARES the script in its plugin.json `trusted_scripts` block
 *     (strict HTTPS URL, attributes, deps, required CSP origins, purpose).
 *  2. An administrator with `trusted_scripts.manage` APPROVES the exact
 *     declaration fingerprint ({@see TrustedScriptApprovalStore}).
 *  3. Plugin code calls {@see enqueue()} during a request. The owner is derived
 *     from the caller's class file ({@see ExtensionIdentityResolver}), never
 *     from a label.
 *  4. The {@see AssetRegistry} — the single rendering authority — pulls the
 *     admitted scripts for THIS request at render time and renders them under
 *     the reserved `ext:{slug}/{key}` handle with its normal ordering rules.
 *
 * Nothing renders unless enqueue() succeeded in the current request. All
 * per-request state (declarations, enqueues, diagnostics, rendered flag) lives
 * on the current Request's attributes, so nothing leaks between requests even
 * under a persistent worker. Core never fetches the declared URL and never
 * emits a CSP header: {@see cspRequirements()} only reports what the scripts
 * need (ADR D-3). Every public method is non-throwing.
 */
class TrustedExternalScriptRegistry
{
    private const STATE_KEY = 'cms.trusted_scripts.state';

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const MAX_DIAGNOSTICS = 100;

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly ExtensionIdentityResolver $identity,
        private readonly TrustedScriptApprovalStore $approvals,
    ) {}

    /**
     * Activate an approved, declared script for the current request. $caller is
     * any object whose class file lives inside the owning active plugin (usually
     * `$this`). Returns false — and records a diagnostic — when the caller is not
     * the owner, the declaration is missing/invalid, or it is not approved.
     */
    public function enqueue(object $caller, string $key): bool
    {
        try {
            $owner = $this->identity->ownerOf($caller);

            if ($owner === null) {
                return $this->deny('-', $key, 'caller not owned by an active plugin');
            }

            $callSite = $this->callSitePlugin();

            if ($callSite !== false && $callSite !== $owner) {
                return $this->deny($owner, $key, 'owner mismatch');
            }

            if (! preg_match(self::KEY_PATTERN, $key)) {
                return $this->deny($owner, $key, 'invalid key');
            }

            $declaration = $this->declarationsByOwner()[$owner][$key] ?? null;

            if ($declaration === null) {
                return $this->deny($owner, $key, 'unknown or invalid declaration');
            }

            if (! $this->approvals->isApproved($declaration)) {
                return $this->deny($owner, $key, $this->approvals->state($declaration) === TrustedScriptApprovalStore::STATE_CHANGED
                    ? 'approval stale: declaration changed'
                    : 'not approved');
            }

            $state = $this->state();
            $state['enqueued'][$declaration->handle()] = $declaration;
            $this->saveState($state);

            return true;
        } catch (Throwable) {
            return $this->deny('-', $key, 'enqueue failed');
        }
    }

    /**
     * Valid declarations of active plugins (approved or not).
     *
     * @return list<TrustedScriptDeclaration>
     */
    public function declarations(): array
    {
        $out = [];

        foreach ($this->declarationsByOwner() as $byKey) {
            foreach ($byKey as $declaration) {
                $out[] = $declaration;
            }
        }

        return $out;
    }

    /** One active plugin's valid declaration, or null. */
    public function declaration(string $owner, string $key): ?TrustedScriptDeclaration
    {
        return $this->declarationsByOwner()[$owner][$key] ?? null;
    }

    /**
     * Rejected declarations of active plugins (owner, key, reason).
     *
     * @return list<array{owner: string, key: ?string, reason: string}>
     */
    public function invalidDeclarations(): array
    {
        $this->declarationsByOwner();

        return $this->state()['invalid'] ?? [];
    }

    /**
     * CSP sources needed by scripts enqueued in this request, or with $all by
     * every APPROVED declaration. Reporting only — never emitted as a header.
     */
    public function cspRequirements(bool $all = false): CspRequirements
    {
        try {
            $declarations = $all
                ? array_values(array_filter($this->declarations(), fn (TrustedScriptDeclaration $d): bool => $this->approvals->isApproved($d)))
                : array_values($this->state()['enqueued']);

            return CspRequirements::fromDeclarations($declarations);
        } catch (Throwable) {
            return CspRequirements::fromDeclarations([]);
        }
    }

    /**
     * Assets the AssetRegistry should render for this request, keyed by handle
     * and in enqueue order. Approval is re-checked here (a revocation in the same
     * request wins). A declaration whose normalised URL was already admitted from
     * another owner becomes a no-output marker that depends on the first, so the
     * script renders once and dependents still resolve (ADR D-4).
     *
     * @return array<string, Asset>
     */
    public function admittedAssets(): array
    {
        try {
            $state = $this->state();
            $assets = [];
            $byUrl = [];
            $sequence = 0;

            foreach ($state['enqueued'] as $handle => $declaration) {
                if (! $this->approvals->isApproved($declaration)) {
                    continue;
                }

                $sequence++;

                if (isset($byUrl[$declaration->url]) && $byUrl[$declaration->url] !== $handle) {
                    $assets[$handle] = new Asset(
                        handle: $handle,
                        type: Asset::TYPE_MARKER,
                        deps: [$byUrl[$declaration->url]],
                        scope: Asset::SCOPE_FRONTEND,
                        sequence: $sequence,
                    );
                    $this->note($declaration->owner, $declaration->key, 'duplicate url: rendered once via '.$byUrl[$declaration->url]);

                    continue;
                }

                $byUrl[$declaration->url] = $handle;
                $assets[$handle] = new Asset(
                    handle: $handle,
                    type: Asset::TYPE_SCRIPT,
                    src: $declaration->url,
                    deps: $declaration->deps,
                    scope: Asset::SCOPE_FRONTEND,
                    position: $declaration->position,
                    attributes: $declaration->attributes,
                    sequence: $sequence,
                );
            }

            return $assets;
        } catch (Throwable) {
            return [];
        }
    }

    /** Record that a trusted script was emitted into this response (cache headers, D-5). */
    public function markRendered(string $handle): void
    {
        $state = $this->state();
        $state['rendered'][$handle] = true;
        $this->saveState($state);
    }

    /** Whether any trusted script was emitted into the current response. */
    public function renderedThisRequest(): bool
    {
        return ($this->state()['rendered'] ?? []) !== [];
    }

    /**
     * Request diagnostics: safe short strings only (no stack traces, no secrets).
     *
     * @return array{enqueued: list<string>, rendered: list<string>, denied: list<array{owner: string, key: string, reason: string}>, notes: list<array{owner: string, key: string, reason: string}>, invalid: list<array{owner: string, key: ?string, reason: string}>}
     */
    public function diagnostics(): array
    {
        $this->declarationsByOwner();
        $state = $this->state();

        return [
            'enqueued' => array_keys($state['enqueued']),
            'rendered' => array_keys($state['rendered']),
            'denied' => $state['denied'],
            'notes' => $state['notes'],
            'invalid' => $state['invalid'] ?? [],
        ];
    }

    /**
     * Counts-only snapshot for /cms-health. Never exposes URLs or owners.
     *
     * @return array{trusted_scripts_ready: bool, trusted_script_declared_count: int, trusted_script_approved_count: int, trusted_script_pending_count: int, trusted_script_invalid_count: int}
     */
    public function healthSnapshot(): array
    {
        $declarations = $this->declarations();
        $approved = count(array_filter($declarations, fn (TrustedScriptDeclaration $d): bool => $this->approvals->isApproved($d)));

        return [
            'trusted_scripts_ready' => true,
            'trusted_script_declared_count' => count($declarations),
            'trusted_script_approved_count' => $approved,
            'trusted_script_pending_count' => count($declarations) - $approved,
            'trusted_script_invalid_count' => count($this->invalidDeclarations()),
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * Declarations of active plugins, re-read from disk once per request so a
     * manifest change is seen immediately (no stale approval). Indexed
     * owner => key => declaration.
     *
     * @return array<string, array<string, TrustedScriptDeclaration>>
     */
    private function declarationsByOwner(): array
    {
        $state = $this->state();

        try {
            $active = $this->extensions->activePlugins();
        } catch (Throwable) {
            $active = [];
        }

        // The memo is only valid for the active plugin set it was built from: an
        // activation/deactivation within the same request rebuilds it.
        $signature = implode("\n", array_map(static fn (Plugin $p): string => $p->slug.'='.$p->path, $active));

        if (is_array($state['declarations']) && ($state['signature'] ?? null) === $signature) {
            return $state['declarations'];
        }

        $byOwner = [];
        $invalid = [];

        try {
            /** @var Plugin $plugin */
            foreach ($active as $plugin) {
                $file = $plugin->path.DIRECTORY_SEPARATOR.'plugin.json';

                if (! File::isFile($file)) {
                    continue;
                }

                $parsed = TrustedScriptManifestParser::parse($plugin->slug, (string) File::get($file));

                foreach ($parsed['declarations'] as $declaration) {
                    $byOwner[$plugin->slug][$declaration->key] = $declaration;
                }

                array_push($invalid, ...$parsed['errors']);
            }
        } catch (Throwable) {
            $byOwner = [];
        }

        $state['declarations'] = $byOwner;
        $state['invalid'] = $invalid;
        $state['signature'] = $signature;
        $this->saveState($state);

        return $byOwner;
    }

    /**
     * The plugin owning the NEAREST stack frame that lives under the plugins root,
     * scanning the whole stack and stepping over Core, vendor, theme and
     * compiled-Blade frames: its slug ('' for an inactive or unresolvable
     * plugin), or false when no plugin frame exists at all (Core/theme call).
     *
     * Defends against plugin B passing an object of plugin A, including through
     * framework indirection such as `app()->call([$registry, 'enqueue'], …)`:
     * stepping over the vendor frames still reaches B's file. The owner's own
     * code calling enqueue() is always the nearest plugin frame, even when
     * another plugin (e.g. a page builder) triggered the render.
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

    private function deny(string $owner, string $key, string $reason): bool
    {
        $this->note($owner, $key, $reason, 'denied');

        return false;
    }

    private function note(string $owner, string $key, string $reason, string $bucket = 'notes'): void
    {
        try {
            $state = $this->state();

            if (count($state[$bucket]) < self::MAX_DIAGNOSTICS) {
                $state[$bucket][] = ['owner' => $this->label($owner), 'key' => $this->label($key), 'reason' => $reason];
                $this->saveState($state);
            }
        } catch (Throwable) {
            // Diagnostics are best-effort.
        }
    }

    /**
     * @return array{declarations: array<string, array<string, TrustedScriptDeclaration>>|null, signature: ?string, invalid: list<array{owner: string, key: ?string, reason: string}>, enqueued: array<string, TrustedScriptDeclaration>, rendered: array<string, true>, denied: list<array{owner: string, key: string, reason: string}>, notes: list<array{owner: string, key: string, reason: string}>}
     */
    private function state(): array
    {
        $empty = ['declarations' => null, 'signature' => null, 'invalid' => [], 'enqueued' => [], 'rendered' => [], 'denied' => [], 'notes' => []];

        try {
            $state = request()->attributes->get(self::STATE_KEY);

            return is_array($state) ? $state + $empty : $empty;
        } catch (Throwable) {
            return $empty;
        }
    }

    private function saveState(array $state): void
    {
        try {
            request()->attributes->set(self::STATE_KEY, $state);
        } catch (Throwable) {
            // No request bound — state is simply not retained.
        }
    }

    private function label(string $value): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9:_\-.\/]/', '', $value), 0, 64);
    }

    private function normalize(string $path): string
    {
        $real = realpath($path);
        $path = str_replace('\\', '/', $real === false ? $path : $real);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
