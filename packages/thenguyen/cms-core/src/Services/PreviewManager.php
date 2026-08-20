<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Contracts\Previewable;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Support\Preview\PreviewContext;
use TheNguyen\CMS\Support\Preview\PreviewDefinition;

/**
 * Core preview infrastructure (v1.0.0-beta.7.1.12.1 — Preview API Polish).
 *
 * Generic, secure, temporary, signed, plugin-friendly. Core provides the
 * plumbing only: content types (core OR plugin) register a resolver — and,
 * preferably, a renderer — for a namespaced preview type. The manager then:
 *
 *   1. Generates temporary SIGNED preview URLs (Laravel signed routes).
 *   2. Resolves a {@see Previewable} back from its type + key.
 *   3. Builds an immutable {@see PreviewContext} for the render.
 *   4. Hands the render decision to the type's renderer callback.
 *   5. Enforces noindex / no-store on every preview response.
 *
 * Everything below is ADDITIVE over the original foundation — the original
 * method signatures still work exactly as before. The polish layer adds a
 * memory-only registry ({@see definitions()}), a policy-aware {@see temporaryUrl()},
 * richer {@see metadata()}, an immutable {@see context()}, and lifecycle hooks +
 * filters so plugins can observe and shape previews without touching core.
 *
 * Core NEVER learns a concrete type's internals; it only knows the contract.
 */
class PreviewManager
{
    /** The signed route the {@see \TheNguyen\CMS\Http\Controllers\PreviewController} answers. */
    public const ROUTE_NAME = 'cms.preview.show';

    /**
     * type => [
     *   'resolver'   => callable(string|int $key): ?Previewable,
     *   'renderer'   => null|callable(Previewable, Request, PreviewContext): mixed,
     *   'definition' => PreviewDefinition,
     * ]
     *
     * @var array<string, array{resolver: callable, renderer: ?callable, definition: PreviewDefinition}>
     */
    private array $types = [];

    /**
     * Register a previewable type.
     *
     * The resolver loads a record by key and returns a {@see Previewable} (or
     * null when missing). The renderer — preferred, and required to preview
     * unpublished records — turns the resolved model into a response using the
     * SAME frontend view as the published page. With no renderer the controller
     * falls back to redirecting to the record's published route (only safe for
     * already-public records).
     *
     * $options is optional descriptive metadata for the registry/health:
     *   ['label' => 'Posts', 'version' => '1.0.0-beta.7.1.12.1']
     *
     * @param  array<string, mixed>  $options
     */
    public function register(string $type, callable $resolver, ?callable $renderer = null, array $options = []): void
    {
        $this->types[$type] = [
            'resolver' => $resolver,
            'renderer' => $renderer,
            'definition' => new PreviewDefinition(
                type: $type,
                hasRenderer: $renderer !== null,
                label: isset($options['label']) && is_string($options['label']) && $options['label'] !== '' ? $options['label'] : null,
                version: isset($options['version']) && (is_string($options['version']) || is_numeric($options['version'])) ? (string) $options['version'] : null,
            ),
        ];
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /**
     * The registered preview type keys.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }

    /** Remove a registered type (memory-only registry — no persistence). */
    public function forgetType(string $type): void
    {
        unset($this->types[$type]);
    }

    /** The safe descriptor for a registered type, or null when unknown. */
    public function definition(string $type): ?PreviewDefinition
    {
        return $this->types[$type]['definition'] ?? null;
    }

    /**
     * Every registered type's safe descriptor, keyed by type. Never exposes the
     * resolver/renderer callables.
     *
     * @return array<string, PreviewDefinition>
     */
    public function definitions(): array
    {
        return array_map(static fn (array $entry): PreviewDefinition => $entry['definition'], $this->types);
    }

    /** How many previewable types are registered. */
    public function registeredCount(): int
    {
        return count($this->types);
    }

    /**
     * Resolve a previewable model from its type + key via the registered
     * resolver. Returns null for an unknown type, a missing record, or a
     * resolver that yields anything other than a {@see Previewable}.
     */
    public function resolve(string $type, string|int $key): ?Previewable
    {
        if (! isset($this->types[$type])) {
            return null;
        }

        $resolved = ($this->types[$type]['resolver'])($key);

        return $resolved instanceof Previewable ? $resolved : null;
    }

    /** The renderer callback for a type, if one was registered. */
    public function renderer(string $type): ?callable
    {
        return $this->types[$type]['renderer'] ?? null;
    }

    /**
     * Build a temporary SIGNED preview URL for a previewable record.
     *
     * Expiry precedence: explicit $expiresAt > options['ttl'] (minutes) > the
     * model's previewExpiresAt() > the configured default TTL. The key is opaque
     * and the signature is required by the route middleware, so the URL is neither
     * predictable nor replayable past expiry, and it never leaks model data in
     * the query string.
     *
     * Policy options (v1.0.0-beta.7.1.12.1):
     *   - 'ttl'           => int minutes, overrides the model/default expiry.
     *   - 'require_login' => bool (default false). When true the flag is baked
     *                        INTO the signature so it cannot be stripped, and the
     *                        controller refuses an unauthenticated viewer.
     *
     * CORE-L10N.1B (P3.1): an optional $locale is signed INTO the URL as the
     * preview render locale (so the locale-aware renderer targets the locale
     * being previewed and the value cannot be tampered/stripped). This is
     * preview render metadata only — the manager owns NO locale policy: the
     * caller ({@see PreviewUrlService}) has already validated the code against
     * the Localization Platform, and no localized frontend route is built here.
     *
     * Lifecycle: fires cms.preview.generating (action) before signing, filters
     * the final URL through cms.preview.url, then fires cms.preview.generated.
     *
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl(Previewable $previewable, ?DateTimeInterface $expiresAt = null, array $options = [], ?string $locale = null): string
    {
        if ($expiresAt === null && isset($options['ttl']) && is_numeric($options['ttl'])) {
            $expiresAt = CarbonImmutable::now()->addMinutes(max(1, (int) $options['ttl']));
        }

        $expiresAt ??= $previewable->previewExpiresAt();

        $params = [
            'type' => $previewable->previewType(),
            'key' => $previewable->previewKey(),
        ];

        // require_login is signed IN, so it cannot be tampered/stripped.
        if (! empty($options['require_login'])) {
            $params['require_login'] = 1;
        }

        // The render locale is signed IN, so the previewed locale cannot be
        // tampered/stripped. The renderer reads it back from the query string.
        if ($locale !== null && $locale !== '') {
            $params['locale'] = $locale;
        }

        $this->fireAction('cms.preview.generating', $previewable, $options);

        $url = URL::temporarySignedRoute(self::ROUTE_NAME, $expiresAt, $params);

        $url = (string) $this->applyFilter('cms.preview.url', $url, $previewable, $options);

        $this->fireAction('cms.preview.generated', $url, $previewable, $options);

        return $url;
    }

    /**
     * Preview metadata for views/logs/plugins. Passed to renderers as
     * $previewMeta and shared to every view as 'previewMeta'.
     *
     * Enriched (v1.0.0-beta.7.1.12.1) and extensible via the cms.preview.metadata
     * filter. Never carries unpublished body/content — only descriptive fields.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function metadata(Previewable $previewable, array $options = []): array
    {
        $expiresAt = $previewable->previewExpiresAt();
        $ttl = isset($options['ttl']) && is_numeric($options['ttl'])
            ? max(1, (int) $options['ttl'])
            : $this->ttlMinutes();

        $meta = [
            'type' => $previewable->previewType(),
            'key' => $previewable->previewKey(),
            'title' => $previewable->previewTitle(),
            'preview' => true,
            'signed' => true,
            'ttl' => $ttl,
            'created_at' => CarbonImmutable::now()->format(DateTimeInterface::ATOM),
            'expires_at' => $expiresAt instanceof DateTimeInterface
                ? $expiresAt->format(DateTimeInterface::ATOM)
                : (string) $expiresAt,
            'generator' => CmsInfo::name(),
            'version' => $this->definition($previewable->previewType())?->version ?? CmsInfo::version(),
        ];

        $filtered = $this->applyFilter('cms.preview.metadata', $meta, $previewable, $options);

        return is_array($filtered) ? $filtered : $meta;
    }

    /**
     * Build the immutable {@see PreviewContext} for a render
     * (v1.0.0-beta.7.1.12.1). Derives expiry/ttl from the signed URL when the
     * request carries them, records who/when, and lets plugins reshape it via
     * the cms.preview.context filter.
     *
     * @param  array<string, mixed>  $extra
     */
    public function context(Previewable $previewable, ?Request $request = null, array $extra = []): PreviewContext
    {
        $request ??= $this->currentRequest();

        $now = CarbonImmutable::now();
        $expiresAt = $this->expiryFromRequest($request) ?? $this->toImmutable($previewable->previewExpiresAt());
        $ttl = max(0, $now->diffInMinutes($expiresAt, false));

        $data = array_merge([
            'preview' => true,
            'preview_type' => $previewable->previewType(),
            'preview_key' => $previewable->previewKey(),
            'signed' => true,
            'ttl' => $ttl,
            'require_login' => $request?->boolean('require_login') ?? false,
            'expires_at' => $expiresAt,
            'generated_at' => $now,
            'generated_by' => $this->currentUserId($request),
            // Convenience for hook consumers; mirrors the hook_context bag.
            'content' => $previewable,
        ], $extra);

        $context = PreviewContext::make($data);

        $filtered = $this->applyFilter('cms.preview.context', $context, $previewable);

        return $filtered instanceof PreviewContext ? $filtered : $context;
    }

    /** Whether the preview subsystem is enabled (config, defaults on). */
    public function enabled(): bool
    {
        return (bool) config('cms.preview.enabled', true);
    }

    /** The default signed-URL lifetime in minutes (config, conservative default). */
    public function ttlMinutes(): int
    {
        return max(1, (int) config('cms.preview.default_ttl_minutes', 30));
    }

    /** The default expiry instant (now + configured TTL) for a fresh preview URL. */
    public function defaultExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes($this->ttlMinutes());
    }

    /**
     * Stamp a preview response so it is never indexed and never cached publicly
     * (SEO-safe + cache-safe). Applied by the controller to every preview
     * response regardless of who rendered it.
     */
    public function applyPreviewHeaders(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    // ------------------------------------------------------------------
    // Internals — hooks are ALWAYS best-effort and never break a preview.
    // ------------------------------------------------------------------

    private function fireAction(string $hook, mixed ...$args): void
    {
        try {
            if (function_exists('do_action')) {
                do_action($hook, ...$args);
            }
        } catch (\Throwable) {
            // A broken listener must never break URL generation / rendering.
        }
    }

    private function applyFilter(string $hook, mixed $value, mixed ...$args): mixed
    {
        try {
            if (function_exists('apply_filters')) {
                return apply_filters($hook, $value, ...$args);
            }
        } catch (\Throwable) {
            // Fall through to the unmodified value.
        }

        return $value;
    }

    private function currentRequest(): ?Request
    {
        try {
            $request = request();

            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The signed URL's expiry (?expires=unix), as an immutable instant. */
    private function expiryFromRequest(?Request $request): ?CarbonImmutable
    {
        if ($request === null) {
            return null;
        }

        $expires = $request->query('expires');

        if ($expires === null || ! is_numeric($expires)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestamp((int) $expires);
        } catch (\Throwable) {
            return null;
        }
    }

    private function currentUserId(?Request $request): int|string|null
    {
        try {
            $id = $request?->user()?->getAuthIdentifier();

            return (is_int($id) || is_string($id)) ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function toImmutable(DateTimeInterface $date): CarbonImmutable
    {
        return $date instanceof CarbonImmutable ? $date : CarbonImmutable::instance($date);
    }
}
