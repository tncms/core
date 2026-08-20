<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use DateTimeInterface;

/**
 * A content type that can generate a secure, temporary frontend preview of an
 * unpublished (draft/pending/scheduled) record (v1.0.0-beta.7.1.16).
 *
 * Core content types AND plugin content types implement this. The
 * {@see \TheNguyen\CMS\Services\PreviewManager} turns an instance into a
 * temporary signed URL and, on the way back, resolves + renders it. Core never
 * needs to know a concrete type's internals — the type registers a resolver +
 * renderer with the manager and satisfies this contract.
 */
interface Previewable
{
    /**
     * Stable, namespaced preview type key, e.g. "cms.page" or
     * "ecommerce.product". Must match the key the type registered with the
     * PreviewManager. Appears in the signed URL, so keep it URL-safe.
     */
    public function previewType(): string;

    /**
     * The opaque identifier the registered resolver uses to load THIS record
     * back (typically the primary key). Appears in the signed URL.
     */
    public function previewKey(): string|int;

    /**
     * The published frontend route name for this record. Used only as a safe
     * fallback (a redirect) when the type registered no renderer; a type that
     * previews drafts should always register a renderer instead.
     */
    public function previewRouteName(): string;

    /**
     * Parameters for {@see previewRouteName()} (e.g. ['slug' => '…']).
     *
     * @return array<string, mixed>
     */
    public function previewRouteParameters(): array;

    /** Human-readable title for the preview (banner, tab title, logs). */
    public function previewTitle(): string;

    /**
     * When this preview's signed URL should expire. Callers may override, but
     * this is the model's own default (typically now + configured TTL).
     */
    public function previewExpiresAt(): DateTimeInterface;
}
