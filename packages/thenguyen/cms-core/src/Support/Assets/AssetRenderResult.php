<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Assets;

/**
 * Immutable result of rendering one asset bucket (scope + position)
 * (v1.0.0-beta.7.1.13.1 — Asset Registry Foundation).
 *
 * Carries the produced HTML plus a list of handles skipped during rendering so
 * callers can report problems without the render itself ever throwing.
 */
final class AssetRenderResult
{
    /**
     * @param  string  $html  Concatenated, safe HTML for the bucket.
     * @param  list<array{handle: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly string $html,
        public readonly array $skipped = [],
    ) {}

    public function __toString(): string
    {
        return $this->html;
    }
}
