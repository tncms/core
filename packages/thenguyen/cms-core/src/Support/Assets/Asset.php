<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Assets;

/**
 * Immutable descriptor for a single registered asset
 * (v1.0.0-beta.7.1.13.1 — Asset Registry Foundation).
 *
 * The {@see \TheNguyen\CMS\Services\AssetRegistry} stores validated
 * registrations as Asset value objects. Payload shape depends on {@see $type};
 * the registry owns dependency resolution and rendering.
 *
 * Handles form a single namespace across style/script/module registrations:
 * re-registering the same handle replaces the earlier definition (last wins).
 */
final class Asset
{
    public const TYPE_STYLE = 'style';

    public const TYPE_SCRIPT = 'script';

    public const TYPE_MODULE = 'module';

    public const TYPE_INLINE_STYLE = 'inline_style';

    public const TYPE_INLINE_SCRIPT = 'inline_script';

    /** Dependency-anchor with no output (e.g. tncms.frontend). */
    public const TYPE_MARKER = 'marker';

    public const SCOPE_FRONTEND = 'frontend';

    public const SCOPE_ADMIN = 'admin';

    public const SCOPE_BOTH = 'both';

    public const POSITION_HEAD = 'head';

    public const POSITION_FOOTER = 'footer';

    /**
     * @param  string  $handle  Unique handle within the asset namespace.
     * @param  string  $type  One of the TYPE_* constants.
     * @param  string|null  $src  Source URL for style/script/module; null for inline/marker.
     * @param  string|null  $code  Inline content for inline_style/inline_script; null otherwise.
     * @param  list<string>  $deps  Handles that must render before this asset.
     * @param  string|null  $version  Cache-busting version appended as ?ver=.
     * @param  string  $scope  frontend, admin, or both.
     * @param  string  $position  head or footer.
     * @param  array<string, scalar|bool>  $attributes  Validated tag attributes.
     * @param  string|null  $target  Handle an inline asset attaches to (before/after).
     * @param  string|null  $relation  'before' or 'after' when $target is set.
     * @param  int  $sequence  Registration order; breaks ties deterministically.
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $type,
        public readonly ?string $src = null,
        public readonly ?string $code = null,
        public readonly array $deps = [],
        public readonly ?string $version = null,
        public readonly string $scope = self::SCOPE_FRONTEND,
        public readonly string $position = self::POSITION_HEAD,
        public readonly array $attributes = [],
        public readonly ?string $target = null,
        public readonly ?string $relation = null,
        public readonly int $sequence = 0,
    ) {}

    /** Return a copy with a different registration sequence. */
    public function withSequence(int $sequence): self
    {
        return new self(
            $this->handle,
            $this->type,
            $this->src,
            $this->code,
            $this->deps,
            $this->version,
            $this->scope,
            $this->position,
            $this->attributes,
            $this->target,
            $this->relation,
            $sequence,
        );
    }

    /** True when this asset participates in the styles bucket. */
    public function isStyle(): bool
    {
        return $this->type === self::TYPE_STYLE || $this->type === self::TYPE_INLINE_STYLE;
    }

    /** True when this asset participates in the scripts bucket. */
    public function isScript(): bool
    {
        return $this->type === self::TYPE_SCRIPT
            || $this->type === self::TYPE_MODULE
            || $this->type === self::TYPE_INLINE_SCRIPT;
    }

    /** True when the asset renders in the given scope. */
    public function inScope(string $scope): bool
    {
        return $this->scope === self::SCOPE_BOTH || $this->scope === $scope;
    }
}
