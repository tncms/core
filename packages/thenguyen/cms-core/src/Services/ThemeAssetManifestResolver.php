<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use TheNguyen\CMS\Support\Assets\Asset;
use TheNguyen\CMS\Support\Assets\ThemeAssetDeclaration;

/**
 * Resolves a theme's declarative asset manifest (theme.json "assets") into a
 * validated, dependency-ordered, owner-aware {@see ThemeAssetManifest}
 * (EG-6 declarative asset manifest, v1.0.0-beta.7.1.24).
 *
 * Responsibilities (§15–§20):
 *   - read the "assets" array of the theme (and, for a child, its declared
 *     parent chain — cycle- and depth-guarded);
 *   - validate every entry: unique handle, safe normalized relative path (no
 *     traversal / absolute / drive / UNC / scheme / null byte), a known type,
 *     file existence, at most one primary stylesheet;
 *   - merge parent + child layers with explicit override and `replaces`
 *     semantics, tracking the OWNER theme of each asset for owner-aware URLs;
 *   - validate the dependency graph: every dependency exists, no cycles, and no
 *     impossible ordering (a head asset may not depend on a footer asset);
 *   - emit a deterministic topological order (stable by declaration sequence).
 *
 * It never renders and never publishes; it only produces the resolved plan. A
 * single validation error makes the whole manifest invalid so activation can
 * fail closed with no partial asset authority.
 */
class ThemeAssetManifestResolver
{
    /** Extension → asset type inference. */
    private const TYPE_BY_EXTENSION = [
        'css' => Asset::TYPE_STYLE,
        'js' => Asset::TYPE_SCRIPT,
        'mjs' => Asset::TYPE_MODULE,
    ];

    private const POSITION_RANK = [
        Asset::POSITION_HEAD => 0,
        Asset::POSITION_FOOTER => 1,
    ];

    public function __construct(private readonly ThemeManager $themes) {}

    /**
     * Resolve the manifest for a theme (standalone) or theme + parent chain
     * (child). Always returns a manifest; check {@see ThemeAssetManifest::isValid()}.
     */
    public function resolve(string $slug): ThemeAssetManifest
    {
        $errors = [];

        // Single source of truth for the parent chain + its cycle/depth/missing
        // guards (EG-6): ThemeManager owns it so views and assets never diverge.
        $resolved = $this->themes->resolveParentChain($slug);
        $chain = $resolved['chain'];
        $errors = array_merge($errors, $resolved['errors']);

        // Parse each layer (root parent first, child last) so declaration order
        // places inherited parent assets before the child's own, and a child
        // handle overrides / replaces a parent handle deterministically.
        /** @var array<string, ThemeAssetDeclaration> $byHandle */
        $byHandle = [];
        /** @var array<string, int> $sequence */
        $sequence = [];
        /** @var array<string, string> $replacements  child handle => replaced parent handle */
        $replacements = [];
        $seq = 0;

        foreach (array_reverse($chain) as $ownerSlug) {
            $manifest = $this->themes->manifest($ownerSlug) ?? [];
            $entries = $manifest['assets'] ?? null;

            if ($entries === null) {
                continue;
            }

            if (! is_array($entries)) {
                $errors[] = "Theme '{$ownerSlug}': \"assets\" must be an array.";

                continue;
            }

            $assetsDir = $this->themes->themeAssetsPath($ownerSlug);
            $layerSeen = [];

            foreach ($entries as $entry) {
                $decl = $this->parseEntry($entry, $ownerSlug, $assetsDir, $errors);

                if ($decl === null) {
                    continue;
                }

                // A duplicate handle WITHIN one theme is a manifest error; a
                // same-handle asset in a MORE-DERIVED layer (child over parent)
                // is a legitimate override handled below.
                if (isset($layerSeen[$decl->handle])) {
                    $errors[] = "Theme '{$ownerSlug}': duplicate asset handle '{$decl->handle}'.";

                    continue;
                }

                $layerSeen[$decl->handle] = true;

                if ($decl->replaces !== null) {
                    $replacements[$decl->handle] = $decl->replaces;
                }

                // Child layer (processed later) overrides a same-handle parent asset
                // but keeps the parent's declaration sequence so siblings never reorder.
                if (isset($byHandle[$decl->handle])) {
                    $byHandle[$decl->handle] = $decl;

                    continue;
                }

                $byHandle[$decl->handle] = $decl;
                $sequence[$decl->handle] = $seq++;
            }
        }

        // Apply explicit parent-handle replacement: drop the replaced parent
        // asset and rewrite any dependency on it to the replacing handle.
        $byHandle = $this->applyReplacements($byHandle, $sequence, $replacements, $errors);

        $errors = array_merge($errors, $this->validateGraph($byHandle));

        $ordered = $errors === []
            ? $this->topologicalOrder($byHandle, $sequence)
            : [];

        return new ThemeAssetManifest($slug, $ordered, $errors);
    }

    /**
     * Validate + resolve one manifest entry into an owner-aware declaration, or
     * null (with a recorded error) when invalid.
     *
     * @param  list<string>  $errors
     */
    private function parseEntry(mixed $entry, string $ownerSlug, string $assetsDir, array &$errors): ?ThemeAssetDeclaration
    {
        if (! is_array($entry)) {
            $errors[] = "Theme '{$ownerSlug}': each asset entry must be an object.";

            return null;
        }

        $handle = $entry['handle'] ?? null;

        if (! $this->isHandleLike($handle)) {
            $errors[] = "Theme '{$ownerSlug}': asset handle is missing or invalid.";

            return null;
        }

        $rawSrc = $entry['src'] ?? null;

        if (! is_string($rawSrc) || $rawSrc === '') {
            $errors[] = "Theme '{$ownerSlug}': asset '{$handle}' has no src.";

            return null;
        }

        $src = $this->normalizeRelativePath($rawSrc);

        if ($src === null) {
            $errors[] = "Theme '{$ownerSlug}': asset '{$handle}' has an unsafe src.";

            return null;
        }

        if (! $this->assetFileExists($assetsDir, $src)) {
            $errors[] = "Theme '{$ownerSlug}': asset '{$handle}' source file does not exist ({$src}).";

            return null;
        }

        $type = $this->resolveType($entry['type'] ?? null, $src);

        if ($type === null) {
            $errors[] = "Theme '{$ownerSlug}': asset '{$handle}' has an unknown type.";

            return null;
        }

        $primary = (bool) ($entry['primary'] ?? false);

        if ($primary && $type !== Asset::TYPE_STYLE) {
            $errors[] = "Theme '{$ownerSlug}': asset '{$handle}' is marked primary but is not a stylesheet.";

            return null;
        }

        $position = $this->resolvePosition($entry['position'] ?? null, $type);
        $deps = $this->normalizeDeps($entry['deps'] ?? null);
        $replaces = is_string($entry['replaces'] ?? null) && trim($entry['replaces']) !== ''
            ? trim($entry['replaces'])
            : null;

        return new ThemeAssetDeclaration(
            handle: (string) $handle,
            type: $type,
            owner: $ownerSlug,
            src: $src,
            url: '/themes/'.$ownerSlug.'/'.$src,
            position: $position,
            deps: $deps,
            primary: $primary,
            version: is_string($entry['version'] ?? null) ? $entry['version'] : null,
            attributes: $this->resolveAttributes($entry, $type),
            replaces: $replaces,
        );
    }

    /**
     * @param  array<string, ThemeAssetDeclaration>  $byHandle
     * @param  array<string, int>  $sequence
     * @param  array<string, string>  $replacements
     * @param  list<string>  $errors
     * @return array<string, ThemeAssetDeclaration>
     */
    private function applyReplacements(array $byHandle, array &$sequence, array $replacements, array &$errors): array
    {
        foreach ($replacements as $childHandle => $replacedHandle) {
            if (! isset($byHandle[$childHandle])) {
                continue;
            }

            if (! isset($byHandle[$replacedHandle])) {
                $errors[] = "Asset '{$childHandle}' replaces unknown handle '{$replacedHandle}'.";

                continue;
            }

            if ($childHandle === $replacedHandle) {
                continue; // plain same-handle override, nothing to rewrite
            }

            // Inherit the replaced asset's ordering slot so dependents keep place.
            $sequence[$childHandle] = $sequence[$replacedHandle];
            unset($byHandle[$replacedHandle]);

            // Rewrite any dependency on the replaced handle to the replacement.
            foreach ($byHandle as $h => $decl) {
                if (in_array($replacedHandle, $decl->deps, true)) {
                    $newDeps = array_values(array_map(
                        static fn (string $d): string => $d === $replacedHandle ? $childHandle : $d,
                        $decl->deps,
                    ));

                    $byHandle[$h] = new ThemeAssetDeclaration(
                        handle: $decl->handle,
                        type: $decl->type,
                        owner: $decl->owner,
                        src: $decl->src,
                        url: $decl->url,
                        position: $decl->position,
                        deps: $newDeps,
                        primary: $decl->primary,
                        version: $decl->version,
                        attributes: $decl->attributes,
                        replaces: $decl->replaces,
                    );
                }
            }
        }

        return $byHandle;
    }

    /**
     * Validate the resolved graph: dependency existence, cycles, impossible
     * ordering (head→footer), and a single primary stylesheet.
     *
     * @param  array<string, ThemeAssetDeclaration>  $byHandle
     * @return list<string>
     */
    private function validateGraph(array $byHandle): array
    {
        $errors = [];
        $primaries = 0;

        foreach ($byHandle as $decl) {
            if ($decl->primary) {
                $primaries++;
            }

            foreach ($decl->deps as $dep) {
                if (! isset($byHandle[$dep])) {
                    $errors[] = "Asset '{$decl->handle}' depends on unknown handle '{$dep}'.";

                    continue;
                }

                $depDecl = $byHandle[$dep];

                if (self::POSITION_RANK[$depDecl->position] > self::POSITION_RANK[$decl->position]) {
                    $errors[] = "Asset '{$decl->handle}' (head) cannot depend on footer asset '{$dep}'.";
                }
            }
        }

        if ($primaries > 1) {
            $errors[] = 'A theme may declare at most one primary stylesheet.';
        }

        $errors = array_merge($errors, $this->detectCycle($byHandle));

        return $errors;
    }

    /**
     * @param  array<string, ThemeAssetDeclaration>  $byHandle
     * @return list<string>
     */
    private function detectCycle(array $byHandle): array
    {
        $state = []; // handle => 'visiting'|'done'
        $errors = [];

        $visit = function (string $handle) use (&$visit, &$state, &$errors, $byHandle): void {
            if (($state[$handle] ?? null) === 'done') {
                return;
            }

            if (($state[$handle] ?? null) === 'visiting') {
                $errors[] = "Circular asset dependency detected at '{$handle}'.";

                return;
            }

            $state[$handle] = 'visiting';

            foreach (($byHandle[$handle]->deps ?? []) as $dep) {
                if (isset($byHandle[$dep])) {
                    $visit($dep);
                }
            }

            $state[$handle] = 'done';
        };

        foreach (array_keys($byHandle) as $handle) {
            $visit($handle);
        }

        return array_values(array_unique($errors));
    }

    /**
     * Deterministic dependency order (dependencies first). Ties break by the
     * stable declaration sequence so the output never depends on hash order.
     *
     * @param  array<string, ThemeAssetDeclaration>  $byHandle
     * @param  array<string, int>  $sequence
     * @return list<ThemeAssetDeclaration>
     */
    private function topologicalOrder(array $byHandle, array $sequence): array
    {
        $order = [];
        $state = [];

        $handles = array_keys($byHandle);
        usort($handles, static fn (string $a, string $b): int => ($sequence[$a] ?? 0) <=> ($sequence[$b] ?? 0));

        $visit = function (string $handle) use (&$visit, &$state, &$order, $byHandle, $sequence): void {
            if (isset($state[$handle])) {
                return;
            }

            $state[$handle] = true;

            $deps = $byHandle[$handle]->deps;
            usort($deps, static fn (string $a, string $b): int => ($sequence[$a] ?? 0) <=> ($sequence[$b] ?? 0));

            foreach ($deps as $dep) {
                if (isset($byHandle[$dep])) {
                    $visit($dep);
                }
            }

            $order[] = $byHandle[$handle];
        };

        foreach ($handles as $handle) {
            $visit($handle);
        }

        return $order;
    }

    // ------------------------------------------------------------------
    // Field resolution + path security
    // ------------------------------------------------------------------

    private function resolveType(mixed $type, string $src): ?string
    {
        if (is_string($type) && $type !== '') {
            $type = strtolower(trim($type));

            return in_array($type, [Asset::TYPE_STYLE, Asset::TYPE_SCRIPT, Asset::TYPE_MODULE], true)
                ? $type
                : null;
        }

        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));

        return self::TYPE_BY_EXTENSION[$ext] ?? null;
    }

    private function resolvePosition(mixed $position, string $type): string
    {
        $default = $type === Asset::TYPE_STYLE ? Asset::POSITION_HEAD : Asset::POSITION_FOOTER;

        if (! is_string($position)) {
            return $default;
        }

        $position = strtolower(trim($position));

        return isset(self::POSITION_RANK[$position]) ? $position : $default;
    }

    /**
     * @return array<string, scalar|bool>
     */
    private function resolveAttributes(array $entry, string $type): array
    {
        $attributes = [];

        if ($type !== Asset::TYPE_STYLE) {
            if (($entry['defer'] ?? false) === true) {
                $attributes['defer'] = true;
            }

            if (($entry['async'] ?? false) === true) {
                $attributes['async'] = true;
            }
        } elseif (is_string($entry['media'] ?? null) && trim($entry['media']) !== '') {
            $attributes['media'] = trim($entry['media']);
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    private function normalizeDeps(mixed $deps): array
    {
        if (! is_array($deps)) {
            return [];
        }

        $out = [];

        foreach ($deps as $dep) {
            if (is_string($dep) && trim($dep) !== '' && ! in_array(trim($dep), $out, true)) {
                $out[] = trim($dep);
            }
        }

        return $out;
    }

    /**
     * Normalize a declared relative asset path, or null when unsafe. Rejects
     * null bytes, URL schemes, protocol-relative and absolute paths, Windows
     * drives, UNC paths, and any ".." traversal segment.
     */
    private function normalizeRelativePath(string $src): ?string
    {
        if ($src === '' || str_contains($src, "\0")) {
            return null;
        }

        // Reject a URL scheme ("http:", "data:", "javascript:") or a Windows
        // drive ("c:"). A leading scheme-like token is never a theme-relative path.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $src) === 1) {
            return null;
        }

        $normalized = str_replace('\\', '/', $src);

        // Absolute ("/x"), protocol-relative ("//host") or UNC ("\\host" → "//host").
        if (str_starts_with($normalized, '/')) {
            return null;
        }

        $segments = explode('/', $normalized);
        $clean = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $clean[] = $segment;
        }

        if ($clean === []) {
            return null;
        }

        return implode('/', $clean);
    }

    /**
     * True when the normalized asset file exists inside the owner's assets/
     * directory and does not escape it (defends against symlinked sources).
     */
    private function assetFileExists(string $assetsDir, string $normalizedSrc): bool
    {
        $full = $assetsDir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedSrc);

        if (! File::isFile($full)) {
            return false;
        }

        $realBase = realpath($assetsDir);
        $realFile = realpath($full);

        if ($realBase === false || $realFile === false) {
            return false;
        }

        $realBase = rtrim(str_replace('\\', '/', $realBase), '/').'/';
        $realFile = str_replace('\\', '/', $realFile);

        return str_starts_with($realFile, $realBase);
    }

    private function isHandleLike(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9][a-z0-9_.\-]*$/i', $value) === 1;
    }
}
