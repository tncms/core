<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (CORE-UNIFIED-OWNERSHIP-R1): marks the lifetime of one
 * HTTP request so content-write authority knows a request is being served
 * (the acting user must then be the signed-in user), and gives that request
 * a server-generated correlation id for the ownership audit. Never reads a
 * client-supplied id.
 */
final class MarkHttpRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $app = app();
        $outer = [$app->bound('cms.http_request_active') ? $app->make('cms.http_request_active') : false,
            $app->bound('cms.correlation_id') ? $app->make('cms.correlation_id') : null];

        $app->instance('cms.http_request_active', true);
        $app->instance('cms.correlation_id', (string) Str::uuid());

        try {
            return $next($request);
        } finally {
            $app->instance('cms.http_request_active', $outer[0]);

            if ($outer[1] === null) {
                $app->forgetInstance('cms.correlation_id');
            } else {
                $app->instance('cms.correlation_id', $outer[1]);
            }
        }
    }
}
