<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Throwable;

/**
 * The ONE Core-owned frontend system-page view resolver
 * (CORE-FRONTEND-SYSTEM-VIEW-RESOLVER-1).
 *
 * Frontend "system pages" are the Core-owned screens that are NOT content
 * (login, register, forgot/reset password, the account dashboard and its
 * profile/security/preferences pages, and the search results page). Each has a
 * stable theme-override target and a frozen
 * Core fallback. This service centralizes that mapping and applies ONE
 * precedence rule everywhere:
 *
 *   theme::<page>   when the active theme ships that view
 *   <core fallback> otherwise (the exact view Core rendered before overrides)
 *
 * This mirrors the long-standing ad-hoc convention already used by the themed
 * error pages ({@see \TheNguyen\CMS\Http\FrontendErrorResponder}) and the
 * storefront search controller, and makes it a single reusable contract. The
 * error responder keeps its own richer, multi-tier, recursion-guarded fallback
 * (specialized → generic → Core self-contained) because an error page must be
 * renderable even while reporting a failure; it implements the SAME precedence
 * policy and is documented as a specialized sibling, not reimplemented here.
 *
 * Security:
 *   - A page is addressed ONLY by a fixed string key from {@see self::PAGES}.
 *     Callers never pass a view name, so no request input, locale, slug, or
 *     query value can ever select an arbitrary Blade template, spoof the
 *     "theme::" namespace, or traverse the filesystem. An unknown key is a
 *     programmer error and throws immediately.
 *   - The "theme::" namespace is registered filesystem-only to a single
 *     authority with a whole-authority default-theme fallback
 *     ({@see \TheNguyen\CMS\Services\ThemeManager::registerViewNamespace()}),
 *     so View::exists('theme::…') is always safe — even with no active theme or
 *     a broken one.
 *
 * Route, guard, validation, throttle, session, redirect, localization and cache
 * behaviour stay entirely with the controllers/middleware. This service only
 * selects and (optionally) renders a presentation template.
 */
final class SystemViewResolver
{
    /**
     * The frozen Core-owned registry: page key => [theme view, Core fallback].
     *
     * The theme view names are the public, frozen override contract. The
     * fallbacks are the exact views Core rendered before this phase (verified
     * against source), so a theme that ships no override produces byte-identical
     * output. "search" falls back to the host-app "frontend.search.index" view
     * (search is a host-app feature); every other page falls back to a packaged
     * "cms::" view.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PAGES = [
        'login' => ['theme::auth.login', 'cms::auth.login'],
        'register' => ['theme::auth.register', 'cms::auth.register'],
        'forgot-password' => ['theme::auth.forgot-password', 'cms::auth.forgot-password'],
        'reset-password' => ['theme::auth.reset-password', 'cms::auth.reset-password'],
        'account' => ['theme::account.index', 'cms::account.dashboard'],
        'profile' => ['theme::account.profile', 'cms::account.profile'],
        'security' => ['theme::account.security', 'cms::account.security'],
        'preferences' => ['theme::account.preferences', 'cms::account.preferences'],
        'sessions' => ['theme::account.sessions', 'cms::account.sessions'],
        'search' => ['theme::search.index', 'frontend.search.index'],
    ];

    /** The noindex directive applied to private system pages at the HTTP layer. */
    private const NOINDEX = 'noindex, nofollow';

    /**
     * The registered system-page keys (stable public contract).
     *
     * @return list<string>
     */
    public function pages(): array
    {
        return array_keys(self::PAGES);
    }

    public function has(string $page): bool
    {
        return isset(self::PAGES[$page]);
    }

    /** The frozen "theme::" override target for a page. */
    public function themeView(string $page): string
    {
        return $this->pair($page)[0];
    }

    /** The frozen Core fallback view for a page. */
    public function fallbackView(string $page): string
    {
        return $this->pair($page)[1];
    }

    /**
     * Resolve the view NAME to render for a page: the theme override when the
     * active theme ships it, otherwise the Core fallback. Pure selection — it
     * renders nothing and is the right entry point when the caller wants to keep
     * returning a view instance (e.g. the search controller).
     */
    public function resolve(string $page): string
    {
        [$themeView, $fallbackView] = $this->pair($page);

        return View::exists($themeView) ? $themeView : $fallbackView;
    }

    /**
     * Render a page to HTML with a recursion-safe theme→Core fallback.
     *
     * If the active theme ships the override, it is attempted first; a broken or
     * looping theme override must never take a system page (e.g. login) offline,
     * so any render-time throwable is reported and the Core fallback — which
     * never loads the theme — renders instead. Only VIEW rendering is guarded:
     * authentication, authorization, validation and other business exceptions
     * occur in the controller BEFORE this call and are never caught here.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderSafely(string $page, array $data = []): string
    {
        [$themeView, $fallbackView] = $this->pair($page);

        if (View::exists($themeView)) {
            try {
                return View::make($themeView, $data)->render();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return View::make($fallbackView, $data)->render();
    }

    /**
     * Render a PRIVATE system page (auth + account) as a response that is
     * guaranteed noindex at the HTTP layer.
     *
     * The Core fallback layouts already emit a <meta name="robots"> tag, but a
     * custom theme override may extend its own layout and forget it. The
     * "X-Robots-Tag: noindex, nofollow" response header enforces the policy at
     * the response layer so these pages stay out of search indexes regardless of
     * the theme's markup. The rendered body is byte-identical to the pre-override
     * fallback when no theme override exists.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderNoindexResponse(string $page, array $data = []): Response
    {
        return new Response(
            $this->renderSafely($page, $data),
            200,
            ['X-Robots-Tag' => self::NOINDEX],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pair(string $page): array
    {
        if (! isset(self::PAGES[$page])) {
            throw new InvalidArgumentException("Unknown frontend system page key [{$page}].");
        }

        return self::PAGES[$page];
    }
}
