<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N.1B (Phase P3.3) — the ONE request-scoped authority that identifies
 * the current frontend resource, independent of SEO, Preview, language
 * switching, routing strategy, and plugin type.
 *
 * It is bound as a REQUEST-SCOPED service (see CmsServiceProvider) so its state
 * is reset at every HTTP request / queue job / Octane request boundary and never
 * leaks across requests, workers, or tests. It stores only a
 * {@see CurrentResourceReference}; it never generates URLs, chooses a locale
 * strategy, or owns SEO state.
 *
 * Write authority (P3.3B): the RENDER BOUNDARY is the sole writer —
 * {@see CurrentResourcePublisher} publishes exactly once, from the controller /
 * render pipeline that knows which resource the request renders (Core does this
 * in {@see \TheNguyen\CMS\Http\Controllers\FrontendController}; a plugin
 * publishes its own resource the same way). SEO is NOT a writer.
 *
 * Write policy is WRITE-ONCE (a lock), so a nested render, widget, Blade
 * component, preview, or stray plugin call can NEVER redefine the current
 * resource: the first {@see publish()} wins and locks the identity; an identical
 * re-publish (same type + identity + owner) is idempotent; a conflicting publish
 * is rejected — it never overwrites — and is recorded for diagnostics
 * ({@see hadConflict()}). Rejection is non-fatal by design: a buggy nested write
 * can never take down a page, yet can never silently redefine the resource
 * either.
 *
 * Read authority: consumers (language switcher, SEO, canonical/hreflang,
 * preview) read only, via {@see current()}; none may reconstruct the current
 * resource independently when the context is set.
 */
final class CurrentResourceContext
{
    private ?CurrentResourceReference $reference = null;

    /** Whether a conflicting (different-identity) write was rejected this request. */
    private bool $conflicted = false;

    /**
     * Publish the current resource under the write-once lock. The first call
     * wins; an identical call is idempotent; a conflicting call is rejected
     * (never overwrites) and flagged.
     */
    public function publish(CurrentResourceReference $reference): void
    {
        // First writer wins and locks the identity for the request.
        if ($this->reference === null) {
            $this->reference = $reference;

            return;
        }

        // Re-publishing the same resource is idempotent.
        if ($this->reference->sameAs($reference)) {
            return;
        }

        // A conflicting write is rejected (locked reference preserved) and
        // recorded — nested overwrite is impossible, misuse is still surfaced.
        $this->conflicted = true;
    }

    public function current(): ?CurrentResourceReference
    {
        return $this->reference;
    }

    public function has(): bool
    {
        return $this->reference !== null;
    }

    public function clear(): void
    {
        $this->reference = null;
        $this->conflicted = false;
    }

    /** Diagnostics: did a conflicting (different-identity) write occur this request? */
    public function hadConflict(): bool
    {
        return $this->conflicted;
    }
}
