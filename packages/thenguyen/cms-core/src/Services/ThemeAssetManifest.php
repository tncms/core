<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Assets\Asset;
use TheNguyen\CMS\Support\Assets\ThemeAssetDeclaration;

/**
 * Immutable result of resolving a theme's declarative asset manifest (EG-6,
 * v1.0.0-beta.7.1.24). Produced by {@see ThemeAssetManifestResolver}.
 *
 * Carries the fully validated, dependency-ordered, owner-aware asset
 * declarations for a theme (standalone) or theme+parent hierarchy (child), plus
 * any validation errors. When {@see isValid()} is false the manifest must be
 * treated as unusable — activation fails closed and no partial asset authority
 * is published (§16, §22).
 */
final class ThemeAssetManifest
{
    /**
     * @param  string  $slug  The (active/child) theme the manifest was resolved for.
     * @param  list<ThemeAssetDeclaration>  $declarations  Dependency-ordered, deduplicated.
     * @param  list<string>  $errors  Validation errors (empty when valid).
     */
    public function __construct(
        public readonly string $slug,
        public readonly array $declarations,
        public readonly array $errors,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function isEmpty(): bool
    {
        return $this->declarations === [];
    }

    /**
     * Register + enqueue every resolved declaration into the AssetRegistry in
     * dependency order, with owner-aware public URLs and theme provenance. The
     * registry re-resolves the dependency graph at render time; feeding it in
     * resolved order keeps a deterministic, stable emission.
     *
     * A manifest that is not valid registers nothing (fail closed).
     */
    public function applyTo(AssetRegistry $registry): void
    {
        if (! $this->isValid()) {
            return;
        }

        foreach ($this->declarations as $asset) {
            $source = ['source_type' => 'theme', 'source_name' => $asset->owner];

            if ($asset->type === Asset::TYPE_STYLE) {
                $registry->registerStyle(
                    handle: $asset->handle,
                    src: $asset->url,
                    deps: $asset->deps,
                    version: $asset->version,
                    scope: Asset::SCOPE_FRONTEND,
                    position: $asset->position,
                    attributes: $asset->attributes,
                    source: $source,
                );
            } elseif ($asset->type === Asset::TYPE_MODULE) {
                $registry->registerModule(
                    handle: $asset->handle,
                    src: $asset->url,
                    deps: $asset->deps,
                    version: $asset->version,
                    scope: Asset::SCOPE_FRONTEND,
                    position: $asset->position,
                    attributes: $asset->attributes,
                    source: $source,
                );
            } else {
                $registry->registerScript(
                    handle: $asset->handle,
                    src: $asset->url,
                    deps: $asset->deps,
                    version: $asset->version,
                    scope: Asset::SCOPE_FRONTEND,
                    position: $asset->position,
                    attributes: $asset->attributes,
                    source: $source,
                );
            }

            $registry->enqueueStyle($asset->handle);
        }
    }

    /**
     * The primary stylesheet handle, or null when none is declared.
     */
    public function primaryHandle(): ?string
    {
        foreach ($this->declarations as $asset) {
            if ($asset->primary) {
                return $asset->handle;
            }
        }

        return null;
    }
}
