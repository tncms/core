<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Seo\CanonicalHost;

/**
 * Applies the canonical host authority (CORE-SEO-RELEASE-HARDENING-R1, H1)
 * before a Core public page or SEO endpoint builds any absolute URL. Attached
 * to Core's public frontend group and robots/sitemap routes; available to
 * other public route groups as the `cms.canonical-host` alias. Admin,
 * Livewire, installer, auth and account routes do not use it.
 */
final class ApplyCanonicalHost
{
    public function __construct(private readonly CanonicalHost $host) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->host->apply($request);

        return $next($request);
    }
}
