<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Preview;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * Immutable runtime context for a preview render (v1.0.0-beta.7.1.12.1).
 *
 * This does NOT invent a second context system: it REUSES {@see HookContext}
 * for the shared runtime accessors (request, locales, theme, route) and layers
 * the preview-specific fields on top. Preview lifecycle hooks/filters receive an
 * instance of this as their final argument.
 *
 * Design rules (mirroring HookContext):
 *  - Immutable: {@see with()} returns a clone, never mutates.
 *  - Defensive: every accessor degrades to a safe default and NEVER throws.
 *  - Cheap: no database queries.
 *
 * Preview-specific fields:
 *   preview, previewType, previewKey, expiresAt, generatedAt, generatedBy,
 *   signed, ttl.
 * Delegated to HookContext:
 *   request, locale, editingLocale, frontendLocale, route, theme.
 */
final class PreviewContext
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly HookContext $hook,
        private readonly array $data = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(array $data = []): self
    {
        // Build the shared HookContext from the SAME bag so its accessors
        // (locale/theme/route/…) resolve identically to a normal hook context.
        return new self(HookContext::make($data), $data);
    }

    // ------------------------------------------------------------------
    // Preview-specific accessors
    // ------------------------------------------------------------------

    /** Whether this is a preview render (always true within the preview flow). */
    public function preview(): bool
    {
        return (bool) ($this->data['preview'] ?? true);
    }

    /** The namespaced preview type, e.g. "cms.post" / "ecommerce.product". */
    public function previewType(): ?string
    {
        return $this->stringOrNull('preview_type');
    }

    /** The opaque record key being previewed. */
    public function previewKey(): string|int|null
    {
        $key = $this->data['preview_key'] ?? null;

        return (is_string($key) || is_int($key)) ? $key : null;
    }

    /** When this preview's signed URL expires (from the URL, when known). */
    public function expiresAt(): ?DateTimeInterface
    {
        return $this->dateOrNull('expires_at');
    }

    /** When this preview context was generated (server time at render). */
    public function generatedAt(): ?DateTimeInterface
    {
        return $this->dateOrNull('generated_at');
    }

    /** The principal (user id) associated with the preview request, if any. */
    public function generatedBy(): int|string|null
    {
        $by = $this->data['generated_by'] ?? null;

        return (is_string($by) || is_int($by)) ? $by : null;
    }

    /** Whether the preview URL was signed (always true through the endpoint). */
    public function signed(): bool
    {
        return (bool) ($this->data['signed'] ?? false);
    }

    /** Remaining lifetime, in minutes, derived from the signed URL expiry. */
    public function ttl(): ?int
    {
        $ttl = $this->data['ttl'] ?? null;

        if (is_int($ttl)) {
            return $ttl;
        }

        return is_numeric($ttl) ? (int) $ttl : null;
    }

    /** Whether the preview URL requires an authenticated viewer (policy). */
    public function requiresLogin(): bool
    {
        return (bool) ($this->data['require_login'] ?? false);
    }

    // ------------------------------------------------------------------
    // Shared runtime accessors (delegated to HookContext — same architecture)
    // ------------------------------------------------------------------

    public function request(): ?Request
    {
        return $this->hook->request();
    }

    public function user(): ?Authenticatable
    {
        return $this->hook->user();
    }

    public function locale(): string
    {
        return $this->hook->locale();
    }

    public function editingLocale(): string
    {
        return $this->hook->editingLocale();
    }

    public function frontendLocale(): string
    {
        return $this->hook->frontendLocale();
    }

    public function route(): ?string
    {
        return $this->hook->routeName();
    }

    public function theme(): ?string
    {
        return $this->hook->theme();
    }

    // ------------------------------------------------------------------
    // Bag access / immutability
    // ------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Return a clone with $key set to $value (immutable).
     */
    public function with(string $key, mixed $value): self
    {
        return new self(
            $this->hook->with($key, $value),
            [...$this->data, $key => $value],
        );
    }

    /** The underlying {@see HookContext} for interop with hook-native code. */
    public function hookContext(): HookContext
    {
        return $this->hook;
    }

    /**
     * A safe, serialisable snapshot (no request/user objects, no callables) —
     * suitable for logging or exposing alongside preview metadata.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preview' => $this->preview(),
            'preview_type' => $this->previewType(),
            'preview_key' => $this->previewKey(),
            'signed' => $this->signed(),
            'ttl' => $this->ttl(),
            'require_login' => $this->requiresLogin(),
            'expires_at' => $this->expiresAt()?->format(DateTimeInterface::ATOM),
            'generated_at' => $this->generatedAt()?->format(DateTimeInterface::ATOM),
            'generated_by' => $this->generatedBy(),
            'locale' => $this->locale(),
            'editing_locale' => $this->editingLocale(),
            'frontend_locale' => $this->frontendLocale(),
            'route' => $this->route(),
            'theme' => $this->theme(),
        ];
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function dateOrNull(string $key): ?DateTimeInterface
    {
        $value = $this->data[$key] ?? null;

        return $value instanceof DateTimeInterface ? $value : null;
    }
}
