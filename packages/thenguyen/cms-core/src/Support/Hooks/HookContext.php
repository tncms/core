<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Hooks;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Runtime context passed to hook/filter (and shortcode) callbacks
 * (v1.0.0-beta.7.1.11.1).
 *
 * A small immutable bag of request-scoped data plus null-safe accessors for the
 * common runtime values an extension wants (current request, authenticated
 * user, locales, active theme, route, Filament panel). Callbacks receive this
 * as their final argument so plugins don't have to reach for global helpers.
 *
 * Design rules:
 *  - Immutable: {@see with()} returns a clone, never mutates.
 *  - Defensive: every accessor degrades to a safe default and NEVER throws,
 *    even when the request / auth / language services are unavailable (e.g.
 *    console, early boot, tests).
 *  - Cheap: no database queries.
 */
final class HookContext
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(private readonly array $data = [])
    {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(array $data = []): self
    {
        return new self($data);
    }

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
        return new self([...$this->data, $key => $value]);
    }

    public function request(): ?Request
    {
        try {
            $request = request();

            return $request instanceof Request ? $request : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function user(): ?Authenticatable
    {
        try {
            return auth()->user();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The application locale (or an explicit `locale` in the data bag).
     */
    public function locale(): string
    {
        $explicit = $this->stringValue('locale');
        if ($explicit !== null) {
            return $explicit;
        }

        try {
            return app()->getLocale();
        } catch (\Throwable) {
            return 'en';
        }
    }

    /**
     * Locale being edited in the admin (falls back to {@see locale()}).
     */
    public function editingLocale(): string
    {
        return $this->stringValue('editing_locale') ?? $this->locale();
    }

    /**
     * Locale the public frontend is rendering in. Prefers an explicit value,
     * then the LanguageManager's current code, then {@see locale()}.
     */
    public function frontendLocale(): string
    {
        $explicit = $this->stringValue('frontend_locale');
        if ($explicit !== null) {
            return $explicit;
        }

        try {
            $code = app('cms.language')->currentCode();
            if (is_string($code) && $code !== '') {
                return $code;
            }
        } catch (\Throwable) {
            // Language manager unavailable — fall through.
        }

        return $this->locale();
    }

    /**
     * Active theme slug, if any.
     */
    public function theme(): ?string
    {
        if ($this->has('theme')) {
            return $this->stringValue('theme');
        }

        try {
            return app('cms.theme')->active()?->slug;
        } catch (\Throwable) {
            return null;
        }
    }

    public function routeName(): ?string
    {
        if ($this->has('route_name')) {
            return $this->stringValue('route_name');
        }

        try {
            return $this->request()?->route()?->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The current Filament panel id (e.g. "admin"), if running inside one.
     */
    public function panel(): ?string
    {
        if ($this->has('panel')) {
            return $this->stringValue('panel');
        }

        try {
            if (class_exists(\Filament\Facades\Filament::class)) {
                return \Filament\Facades\Filament::getCurrentPanel()?->getId();
            }
        } catch (\Throwable) {
            // Outside a panel / Filament not booted.
        }

        return null;
    }

    /**
     * Read a data-bag value only when it is a non-empty string.
     */
    private function stringValue(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
