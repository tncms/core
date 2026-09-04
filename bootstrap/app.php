<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TheNguyen\CMS\Http\FrontendErrorResponder;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // CORE-FRONTEND-1: brand public frontend HTML error pages (404/403/419/
        // 429/500/503) through the active theme, with a recursion-safe Core
        // fallback. API/JSON, admin, installer, upgrade, Livewire, auth and
        // validation responses are never themed — the responder returns null so
        // the framework keeps their expected contract.
        $exceptions->render(function (Throwable $e, Request $request): ?SymfonyResponse {
            return app(FrontendErrorResponder::class)->render($e, $request);
        });
    })->create();
