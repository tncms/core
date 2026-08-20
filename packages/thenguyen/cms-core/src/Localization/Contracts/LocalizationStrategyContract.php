<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Contracts;

use Illuminate\Http\Request;
use TheNguyen\CMS\Localization\RouteDescriptor;

/**
 * CORE-L10N.1B — a Core-owned localization strategy: it decides HOW localization is applied
 * (URL prefix, session, and — in future — domain/subdomain), and produces the final localized
 * URL + switch-action metadata for a route descriptor.
 *
 * Strategies are STATELESS: every method depends only on its explicit inputs (request,
 * descriptor, locale) and injected read-only collaborators. No request/resource state is
 * retained between calls. Plugins never see, select, or configure a strategy — Core resolves
 * the active strategy from `language.routing_strategy`.
 */
interface LocalizationStrategyContract
{
    /** The unique strategy key, e.g. 'prefix' | 'session'. */
    public function key(): string;

    /**
     * Resolve the public-content locale for the current request under this strategy. Always
     * returns a valid enabled locale (falls back to the default), never null.
     */
    public function resolveLocale(Request $request): string;

    /**
     * Build the final absolute localized URL for $descriptor under $locale. The prefix
     * strategy prepends the Core prefix to the descriptor's canonical path; the session
     * strategy keeps the current request path (locale is carried by persistence, not the URL).
     */
    public function url(RouteDescriptor $descriptor, string $locale, Request $request): string;

    /** The HTTP method a switcher must use for this strategy: 'get' or 'post'. */
    public function switchMethod(): string;

    /**
     * Switch-action metadata for strategies that persist a locale (POST) rather than link
     * (GET). Returns null for GET strategies.
     *
     * @return array{action: ?string, fields: array<string, string>}|null
     */
    public function switchAction(string $locale, string $targetUrl, Request $request): ?array;
}
