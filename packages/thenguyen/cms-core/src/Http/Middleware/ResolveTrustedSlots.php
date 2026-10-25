<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheNguyen\CMS\Support\TrustedComponents\TrustedSlotVault;

/**
 * The final-response boundary of trusted interactive components
 * (CORE-EXTENSION-TRUST S2, ADR D-C/D-7/D-9). Alias: `cms.trusted_slots`.
 *
 * Attached to the Core frontend group (innermost, after
 * {@see OptimizeResponseHeaders}) and the signed preview route; plugin routes
 * opt in through the alias. Never on `web` globally, so admin, Livewire,
 * installer, auth/account and health routes are never transformed.
 *
 * On the way in it activates this request's {@see TrustedSlotVault}, which is
 * what allows `cms_trusted_component()` to mint at all. On the way out it
 * seals the vault, then:
 *
 *   - 200 text/html, identity encoding → each minted token is replaced by its
 *     validated component HTML once; every other slot wrapper or bare token
 *     (forged, copied, replayed, duplicated) is removed;
 *   - non-200 text/html → wrappers and tokens removed, nothing inserted;
 *   - JSON / other types, streamed, binary or encoded bodies → untouched;
 *   - if anything was minted on this request → `Cache-Control: no-store,
 *     private` (overriding any public caching), `Expires` removed.
 *
 * It only inserts what the registry already authorized and validated during
 * this request; it never calls providers, never re-sanitizes and never
 * re-scans inserted HTML. Responses without slots are left exactly as built.
 */
class ResolveTrustedSlots
{
    public function handle(Request $request, Closure $next): Response
    {
        $vault = TrustedSlotVault::forRequest($request);
        $vault->activate();

        try {
            $response = $next($request);
        } finally {
            $vault->seal();
        }

        try {
            // A stronger existing `no-store, private` (e.g. preview's
            // `must-revalidate, no-cache, no-store, private`) is kept as is.
            if ($vault->mintedCount() > 0 && ! ($response->headers->hasCacheControlDirective('no-store') && $response->headers->hasCacheControlDirective('private'))) {
                $response->headers->set('Cache-Control', 'no-store, private');
                $response->headers->remove('Expires');
            }

            if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
                return $response;
            }

            $encoding = strtolower(trim((string) $response->headers->get('Content-Encoding', '')));

            if ($encoding !== '' && $encoding !== 'identity') {
                return $response;
            }

            if (! str_contains(strtolower((string) $response->headers->get('Content-Type', 'text/html')), 'text/html')) {
                return $response;
            }

            $content = $response->getContent();

            if (! is_string($content) || stripos($content, 'TNCMSSLOT') === false) {
                return $response;
            }

            $result = $vault->resolve($content, insert: $response->getStatusCode() === 200);
            $response->setContent($result['body']);

            if ($response->headers->has('Content-Length')) {
                $response->headers->set('Content-Length', (string) strlen($result['body']));
            }
        } catch (\Throwable $e) {
            report($e);

            // Fail closed: never leave a placeholder or token in the body.
            try {
                $content = $response->getContent();

                if (is_string($content) && stripos($content, 'TNCMSSLOT') !== false) {
                    $response->setContent((string) preg_replace('#(?i:TNCMSSLOT)[0-9a-f]{32}#i', '', $content));
                }
            } catch (\Throwable) {
                // Nothing more can be done safely.
            }
        }

        return $response;
    }
}
