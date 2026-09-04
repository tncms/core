<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Assets;

/**
 * A single validated, resolved theme asset declared in theme.json's "assets"
 * array (EG-6 declarative asset manifest, v1.0.0-beta.7.1.24).
 *
 * Unlike {@see Asset} (the imperative registry's internal descriptor), this is
 * the DECLARATIVE, owner-aware resolution of one manifest entry: it knows which
 * theme in the (standalone or parent/child) hierarchy owns the file, so the
 * public URL is built from the OWNER's slug — never blindly from the active
 * child slug. {@see \TheNguyen\CMS\Services\ThemeAssetManifest} produces these,
 * and applies them to the {@see \TheNguyen\CMS\Services\AssetRegistry}.
 */
final class ThemeAssetDeclaration
{
    /**
     * @param  string  $handle  Unique handle within the resolved hierarchy.
     * @param  string  $type  Asset::TYPE_STYLE|TYPE_SCRIPT|TYPE_MODULE.
     * @param  string  $owner  Slug of the theme that OWNS the source file.
     * @param  string  $src  Normalized relative path under the owner's assets/.
     * @param  string  $url  Owner-aware public URL (/themes/{owner}/{src}).
     * @param  string  $position  Asset::POSITION_HEAD|POSITION_FOOTER.
     * @param  list<string>  $deps  Handles this asset depends on.
     * @param  bool  $primary  True for the theme's primary stylesheet.
     * @param  string|null  $version  Cache-busting version (?ver=).
     * @param  array<string, scalar|bool>  $attributes  defer/async/media/etc.
     * @param  string|null  $replaces  Parent handle this asset replaces (child themes).
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $type,
        public readonly string $owner,
        public readonly string $src,
        public readonly string $url,
        public readonly string $position,
        public readonly array $deps = [],
        public readonly bool $primary = false,
        public readonly ?string $version = null,
        public readonly array $attributes = [],
        public readonly ?string $replaces = null,
    ) {}

    public function isStyle(): bool
    {
        return $this->type === Asset::TYPE_STYLE;
    }

    public function isScript(): bool
    {
        return $this->type === Asset::TYPE_SCRIPT || $this->type === Asset::TYPE_MODULE;
    }
}
