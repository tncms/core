<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;
use TheNguyen\CMS\Contracts\Previewable;
use TheNguyen\CMS\Services\PreviewManager;
use TheNguyen\CMS\Support\Preview\PreviewContext;

/**
 * Serves secure, temporary, SIGNED frontend previews of unpublished content
 * (v1.0.0-beta.7.1.12.1). Route: GET /cms/preview/{type}/{key}, name
 * cms.preview.show, middleware ['web', 'signed'].
 *
 * The 'signed' middleware validates the signature + expiry BEFORE this runs, so
 * an unsigned, tampered, or expired URL never reaches here. Core does not know
 * how to render any specific type: it resolves a {@see Previewable} through the
 * {@see PreviewManager}, builds an immutable {@see PreviewContext}, delegates
 * rendering to the type's registered renderer, then stamps the response noindex
 * + no-store.
 *
 * Lifecycle hooks (all best-effort, never break the response):
 *   actions: cms.preview.rendering, cms.preview.rendered, cms.preview.denied,
 *            cms.preview.expired
 *   filters: cms.preview.response
 * (Generation-side hooks — generating/generated/url/metadata/context — live in
 * the {@see PreviewManager}.) Every callback receives the PreviewContext as its
 * final argument.
 */
class PreviewController
{
    public function __construct(private readonly PreviewManager $preview)
    {
    }

    public function __invoke(Request $request, string $type, string $key): Response
    {
        // A disabled subsystem behaves as if the route does not exist.
        if (! $this->preview->enabled()) {
            abort(404);
        }

        // Defense-in-depth: the 'signed' middleware already rejects expired URLs
        // with a 403 before we run, but if that guarantee is ever bypassed we
        // still refuse — and emit the expired lifecycle hook.
        if ($this->isExpired($request)) {
            $this->fireDenied('cms.preview.expired', $request, $type, $key, 'expired');
            abort(403);
        }

        $previewable = $this->preview->resolve($type, $key);

        if ($previewable === null) {
            $this->fireDenied('cms.preview.denied', $request, $type, $key, 'not_found');
            abort(404);
        }

        // Policy (v1.0.0-beta.7.1.12.1): require_login is signed INTO the URL, so
        // it cannot be stripped. We only ENFORCE the policy here using the
        // application's existing auth — no new authentication logic.
        if ($request->boolean('require_login') && ! $this->isAuthenticated($request)) {
            $this->fireDenied('cms.preview.denied', $request, $type, $key, 'require_login');
            abort(403);
        }

        // Flag the request as preview mode so downstream renderers/middleware
        // (and, if a plugin inspects it, the theme) can adapt.
        $request->attributes->set('cms_preview', true);
        $request->attributes->set('cms_preview_type', $type);
        $request->attributes->set('cms_preview_key', $key);

        $context = $this->preview->context($previewable, $request);
        $meta = $this->preview->metadata($previewable);

        // Make preview state available to EVERY view rendered on this request,
        // so themes/partials can show a banner even if a renderer forgot to pass
        // it. Renderers still pass these explicitly (belt and suspenders).
        View::share('isPreview', true);
        View::share('previewMeta', $meta);
        View::share('previewContext', $context);

        $this->fireAction('cms.preview.rendering', $previewable, $context);

        $renderer = $this->preview->renderer($type);

        if ($renderer === null) {
            // No renderer: the safest generic fallback is a redirect to the
            // record's published route. Only useful for already-public records;
            // a type that previews drafts should register a renderer.
            $response = $this->preview->applyPreviewHeaders(
                redirect()->route(
                    $previewable->previewRouteName(),
                    $previewable->previewRouteParameters(),
                )
            );

            $this->fireAction('cms.preview.rendered', $previewable, $context);

            return $response;
        }

        // Existing renderers accept ($previewable) or ($previewable, $request);
        // PHP allows the extra $context argument, so older callbacks keep working.
        $rendered = $renderer($previewable, $request, $context);

        $response = $this->preview->applyPreviewHeaders(
            $this->toResponse($request, $rendered)
        );

        $filtered = apply_filters('cms.preview.response', $response, $previewable, $context);
        if ($filtered instanceof Response) {
            // A filter may only harden headers; re-stamp so it can never opt out
            // of noindex / no-store.
            $response = $this->preview->applyPreviewHeaders($filtered);
        }

        $this->fireAction('cms.preview.rendered', $previewable, $context);

        return $response;
    }

    /**
     * Whether the signed URL's expiry timestamp is already in the past. The
     * 'signed' middleware normally makes this impossible; this is a redundant
     * guard so the expired lifecycle hook has a firing point.
     */
    private function isExpired(Request $request): bool
    {
        $expires = $request->query('expires');

        return $expires !== null && is_numeric($expires) && (int) $expires < now()->getTimestamp();
    }

    private function isAuthenticated(Request $request): bool
    {
        try {
            return $request->user() !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fireAction(string $hook, Previewable $previewable, PreviewContext $context): void
    {
        try {
            if (function_exists('do_action')) {
                do_action($hook, $previewable, $context);
            }
        } catch (\Throwable) {
            // A broken listener must never break the preview response.
        }
    }

    /**
     * Emit a denial/expiry lifecycle action with a minimal context (we may not
     * have a resolved record). Always best-effort.
     */
    private function fireDenied(string $hook, Request $request, string $type, string|int $key, string $reason): void
    {
        try {
            if (! function_exists('do_action') || ! function_exists('preview_context')) {
                return;
            }

            $context = preview_context([
                'preview' => true,
                'preview_type' => $type,
                'preview_key' => $key,
                'signed' => true,
                'denied_reason' => $reason,
            ]);

            do_action($hook, $type, $key, $reason, $context);
        } catch (\Throwable) {
            // Never let a denial hook itself turn a 403/404 into a 500.
        }
    }

    /**
     * Normalize whatever a renderer returns (Response, Responsable, View or
     * string) into a Symfony Response we can stamp headers on.
     */
    private function toResponse(Request $request, mixed $rendered): Response
    {
        if ($rendered instanceof Response) {
            return $rendered;
        }

        if ($rendered instanceof Responsable) {
            return $rendered->toResponse($request);
        }

        return response($rendered);
    }
}
