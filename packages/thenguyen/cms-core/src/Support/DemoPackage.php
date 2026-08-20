<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

use Illuminate\Support\Facades\File;

/**
 * An immutable description of a demo package discovered on disk, owned by a
 * theme or a plugin (theme-architecture 16, generalized).
 *
 * A package lives at `{owner_root}/demo/{slug}/manifest.json` where the owner
 * root is a theme directory (`themes/{owner}`) or an active plugin directory
 * (`plugins/{owner}`). The manifest is the generic format:
 *
 *   {
 *     "type": "theme" | "plugin",
 *     "owner": "default",
 *     "slug": "company",
 *     "name": "Company Demo",
 *     "description": "...",
 *     "version": "1.0.0",
 *     "preview_image": "preview.png",
 *     "preset": "company",                 // optional, theme packages only
 *     "requires": { "cms": ">=1.0.0", "plugins": [] },
 *     "files": { "homepage": "homepage.json", "media": "media.json", ... },
 *     "handlers": { "products": "Vendor\\Plugin\\Demo\\ProductHandler" }
 *   }
 *
 * `files` maps a logical name (media, theme_options, homepage, menus, contents,
 * terms, or any plugin-specific name) to a JSON filename inside the package.
 * `handlers` optionally maps a logical name to a {@see \TheNguyen\CMS\Contracts\DemoImportHandler}
 * class. The core never references a specific theme/plugin — everything is read
 * from the manifest.
 */
final class DemoPackage
{
    public const TYPE_THEME = 'theme';

    public const TYPE_PLUGIN = 'plugin';

    /**
     * @param  array<string, string>  $files  logical name => filename
     * @param  array<string, string>  $handlers  logical name => handler class
     * @param  array<string, mixed>  $requires
     * @param  array<int, string>  $warnings  manifest-declared pre-import notices
     * @param  array<string, mixed>  $manifest  the raw manifest
     */
    public function __construct(
        public readonly string $type,
        public readonly string $owner,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $version,
        public readonly string $path,
        public readonly ?string $previewImage = null,
        public readonly ?string $preset = null,
        public readonly array $files = [],
        public readonly array $handlers = [],
        public readonly array $requires = [],
        public readonly bool $rollbackSupported = true,
        public readonly array $warnings = [],
        public readonly array $manifest = [],
    ) {}

    /**
     * Build a package from a manifest array and its on-disk directory, or null
     * when the manifest is structurally invalid (so discovery can skip it).
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function fromManifest(array $manifest, string $path): ?self
    {
        $type = is_string($manifest['type'] ?? null) ? $manifest['type'] : '';
        $owner = is_string($manifest['owner'] ?? null) ? $manifest['owner'] : '';
        $slug = is_string($manifest['slug'] ?? null) ? $manifest['slug'] : '';
        $name = is_string($manifest['name'] ?? null) ? $manifest['name'] : '';
        $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '';

        if (! in_array($type, [self::TYPE_THEME, self::TYPE_PLUGIN], true)) {
            return null;
        }

        if (! self::isSlug($owner) || ! self::isSlug($slug) || $name === '' || $version === '') {
            return null;
        }

        return new self(
            type: $type,
            owner: $owner,
            slug: $slug,
            name: $name,
            description: is_string($manifest['description'] ?? null) ? $manifest['description'] : '',
            version: $version,
            path: $path,
            previewImage: is_string($manifest['preview_image'] ?? null) && $manifest['preview_image'] !== '' ? $manifest['preview_image'] : null,
            preset: is_string($manifest['preset'] ?? null) && $manifest['preset'] !== '' ? $manifest['preset'] : null,
            files: self::stringMap($manifest['files'] ?? null),
            handlers: self::stringMap($manifest['handlers'] ?? null),
            requires: is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [],
            rollbackSupported: (bool) (($manifest['rollback']['supported'] ?? true)),
            warnings: self::stringList($manifest['warnings'] ?? null),
            manifest: $manifest,
        );
    }

    public function isTheme(): bool
    {
        return $this->type === self::TYPE_THEME;
    }

    public function isPlugin(): bool
    {
        return $this->type === self::TYPE_PLUGIN;
    }

    /**
     * A stable identity for the package across discovery: "{type}:{owner}:{slug}".
     */
    public function id(): string
    {
        return $this->type.':'.$this->owner.':'.$this->slug;
    }

    /**
     * The filename declared for a logical file (e.g. "homepage"), or null.
     */
    public function fileName(string $logical): ?string
    {
        return $this->files[$logical] ?? null;
    }

    /**
     * Absolute path to a logical file, with a realpath containment guard. Returns
     * null when the file is not declared, missing, or escapes the package dir.
     */
    public function filePath(string $logical): ?string
    {
        $name = $this->fileName($logical);

        if ($name === null) {
            return null;
        }

        return $this->resolveInside($name);
    }

    /**
     * Absolute path to the preview image, or null when none / unsafe / missing.
     */
    public function previewImagePath(): ?string
    {
        if ($this->previewImage === null) {
            return null;
        }

        return $this->resolveInside($this->previewImage);
    }

    /**
     * The declared handler class for a logical file, or null.
     */
    public function handlerClass(string $logical): ?string
    {
        return $this->handlers[$logical] ?? null;
    }

    /**
     * Resolve a package-relative path with a traversal/containment guard.
     */
    private function resolveInside(string $relative): ?string
    {
        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        if (preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $relative) === 1) {
            return null;
        }

        $candidate = $this->path.DIRECTORY_SEPARATOR.str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);

        if (! File::exists($candidate)) {
            return null;
        }

        $realRoot = realpath($this->path);
        $realFile = realpath($candidate);

        if ($realRoot === false || $realFile === false || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item) && $item !== '') {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
    }

    private static function isSlug(string $value): bool
    {
        return $value !== '' && preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $value) === 1;
    }
}
