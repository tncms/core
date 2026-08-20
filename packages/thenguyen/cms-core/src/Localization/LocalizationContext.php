<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;

/**
 * CORE-L10N.1B — the explicit input handed to resolvers to describe the current localization
 * target.
 *
 * It carries the resolved resource (a Content, a Term, or neither for the home/unknown case)
 * plus its context type. Passing this VO — instead of letting resolvers reach into request()
 * or global state — keeps resolvers pure and testable (architecture lock: resolver purity).
 * A resolver decides whether it supports the context and, if so, produces route facts from it.
 *
 * CORE-L10N.1B (P3.3): it may additionally carry a generic {@see CurrentResourceReference} so a
 * PLUGIN resource (e.g. 'ecommerce.product') that is neither a Content nor a Term can flow through
 * the same pipeline. Core resolvers keep reading the legacy $content/$term slots unchanged; plugin
 * resolvers match on $type and read the backing object via {@see resource()}. The field is optional
 * and last — every existing (type, content, term) construction is byte-compatible.
 */
final class LocalizationContext
{
    public function __construct(
        public readonly string $type,
        public readonly ?Content $content = null,
        public readonly ?Term $term = null,
        public readonly ?CurrentResourceReference $reference = null,
    ) {}

    public function isHome(): bool
    {
        return $this->type === 'home' || $this->type === 'default';
    }

    /**
     * The backing resource object for this context, from the generic reference
     * when present, else the legacy Content/Term. Null for home/unknown.
     */
    public function resource(): ?object
    {
        return $this->reference?->resource ?? $this->content ?? $this->term;
    }
}
