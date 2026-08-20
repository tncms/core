<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

/**
 * The result of resolving a pagebuilder/06 document: the ordered, typed
 * sections ready for Blade, plus any non-fatal warnings collected while
 * resolving (unknown types, invalid values, unresolved media). Resolution is
 * tolerant and never throws — callers render `sections` and may log `warnings`.
 */
final class ResolvedLayout
{
    /**
     * @param  array<int, SectionViewModel>  $sections
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly array $sections = [],
        public readonly array $warnings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }
}
