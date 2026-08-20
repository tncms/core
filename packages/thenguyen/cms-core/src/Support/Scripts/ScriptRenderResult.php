<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Scripts;

/**
 * Immutable result of rendering a script location (v1.0.0-beta.7.1.13).
 *
 * Carries the produced HTML plus a list of assets skipped during rendering so
 * callers can report problems without the render itself ever throwing.
 */
final class ScriptRenderResult
{
    /**
     * @param  string  $html  Concatenated, safe HTML for the location.
     * @param  list<array{key: string, type: string, reason: string}>  $skipped
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
