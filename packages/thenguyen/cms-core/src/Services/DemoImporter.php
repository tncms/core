<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use TheNguyen\CMS\Contracts\DemoImportHandler;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Support\CmsInfo;
use TheNguyen\CMS\Support\DemoImportContext;
use TheNguyen\CMS\Support\DemoPackage;
use TheNguyen\CMS\Support\ImportResult;

/**
 * Generic demo importer for theme AND plugin demo packages (theme-architecture
 * 16, generalized).
 *
 * Discovery scans `themes/{theme}/demo/*` for every installed theme and
 * `plugins/{plugin}/demo/*` for every ACTIVE plugin, building a {@see DemoPackage}
 * from each generic manifest. The core never references a specific theme/plugin.
 *
 * Import is type-aware but driven entirely by the manifest's `files` map:
 *   - `media`         → cms_media rows for bundled assets, mapping each import
 *                       key to a media id (both package types);
 *   - `theme_options` → theme option values (theme packages only);
 *   - `homepage`      → theme.{owner}.homepage_layout, a validated pagebuilder/06
 *                       document with media import-key refs resolved (theme only);
 *   - any other file  → dispatched to the {@see DemoImportHandler} the manifest
 *                       declares in `handlers`; a missing/unimplemented handler is
 *                       skipped with a clear warning (never a hard failure).
 *
 * For theme packages an optional manifest `preset` sets theme.{owner}.homepage_preset.
 * Imports are idempotent (re-import reuses the original pre-import snapshot and
 * existing media rows) and reversible via reset() for the settings/layout the
 * core writes. It never throws to the caller — every outcome is an {@see ImportResult}.
 */
class DemoImporter
{
    /** Logical files the core imports natively (everything else needs a handler). */
    private const NATIVE_FILES = ['media', 'theme_options', 'homepage', 'menus'];

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ThemeOptionManager $themeOptions,
        private readonly SectionResolver $sections,
        private readonly MediaManager $media,
        private readonly ExtensionManager $extensions,
        private readonly DemoMenuImporter $menus,
    ) {}

    /**
     * Discover every valid demo package: themes and active plugins.
     *
     * @return array<string, DemoPackage> keyed by package id ("type:owner:slug")
     */
    public function discover(): array
    {
        $out = [];

        foreach ($this->extensions->themes() as $theme) {
            $this->scan(DemoPackage::TYPE_THEME, $theme->slug, $theme->path, $out);
        }

        foreach ($this->extensions->activePlugins() as $plugin) {
            $this->scan(DemoPackage::TYPE_PLUGIN, $plugin->slug, $plugin->path, $out);
        }

        ksort($out);

        return $out;
    }

    /**
     * Find a discovered package by owner + slug, or null.
     */
    public function find(string $owner, string $slug): ?DemoPackage
    {
        foreach ($this->discover() as $package) {
            if ($package->owner === $owner && $package->slug === $slug) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Import a demo package. Idempotent and reversible (for core-written state).
     */
    public function import(DemoPackage $package): ImportResult
    {
        $owner = $package->owner;
        $slug = $package->slug;

        // --- Preflight ---
        $preflightErrors = $this->preflight($package);

        if ($preflightErrors !== []) {
            return ImportResult::failure($owner, $slug, 'Demo requirements not met.', $preflightErrors);
        }

        // --- Validate the homepage layout BEFORE any writes (fail fast) ---
        $homepageFile = $package->isTheme() ? $package->filePath('homepage') : null;
        $layoutDoc = null;

        if ($homepageFile !== null) {
            $layoutDoc = $this->readJson($homepageFile);

            if (! $this->isLayoutDocument($layoutDoc)) {
                return ImportResult::failure(
                    $owner,
                    $slug,
                    'The homepage file is not a valid pagebuilder/06 document.',
                    ['Missing or invalid "sections" array.'],
                );
            }
        }

        $layoutKey = $this->settingKey($owner, 'homepage_layout');
        $presetKey = $this->settingKey($owner, 'homepage_preset');
        $provenanceKey = $this->provenanceKey($owner, $slug);

        $existing = $this->settings->get($provenanceKey);
        $isReimport = is_array($existing);

        // Menus the previous import created — pruned if dropped from menus.json.
        $prevMenuIds = $isReimport && is_array($existing['imported_menu_ids'] ?? null)
            ? $existing['imported_menu_ids']
            : [];

        // Snapshots: capture the true pre-import state once; reuse it on re-import
        // so reset always restores the original (not demo) values.
        if ($isReimport) {
            $settingsSnapshot = is_array($existing['settings_snapshot'] ?? null) ? $existing['settings_snapshot'] : [];
            $layoutSnapshot = is_array($existing['layout_snapshot'] ?? null)
                ? $existing['layout_snapshot']
                : ['existed' => false, 'value' => null];
            $batchId = is_string($existing['batch_id'] ?? null) && $existing['batch_id'] !== ''
                ? $existing['batch_id']
                : (string) Str::ulid();
        } else {
            $settingsSnapshot = [];
            $layoutSnapshot = $package->isTheme()
                ? ['existed' => $this->settings->has($layoutKey), 'value' => $this->settings->get($layoutKey)]
                : ['existed' => false, 'value' => null];
            $batchId = (string) Str::ulid();
        }

        $capture = function (string $key) use (&$settingsSnapshot, $isReimport): void {
            if ($isReimport || array_key_exists($key, $settingsSnapshot)) {
                return;
            }

            $settingsSnapshot[$key] = [
                'existed' => $this->settings->has($key),
                'value' => $this->settings->get($key),
            ];
        };

        $steps = [];
        $skipped = [];
        $warnings = [];
        $handled = [];

        // --- 1. media (both package types) — import bundled assets, map keys → ids ---
        $existingKeys = $isReimport && is_array($existing['imported_keys'] ?? null) ? $existing['imported_keys'] : [];
        $importedKeys = [];

        if ($package->fileName('media') !== null) {
            $handled['media'] = true;
            $mediaFile = $package->filePath('media');

            if ($mediaFile !== null) {
                [$importedKeys, $mediaWarnings, $mediaImported] = $this->importMedia($package->path, $mediaFile, $existingKeys);
                foreach ($mediaWarnings as $warning) {
                    $warnings[] = 'media: '.$warning;
                }
                $mediaImported ? $steps[] = 'media' : $skipped[] = 'media';
            } else {
                $warnings[] = 'media file is missing or unsafe — skipped.';
                $skipped[] = 'media';
            }
        }

        // --- 1b. menus (both package types) — import header/footer navigation ---
        $menuIds = [];

        if ($package->fileName('menus') !== null) {
            $handled['menus'] = true;
            $menusFile = $package->filePath('menus');

            if ($menusFile !== null) {
                $menusData = $this->readJson($menusFile);

                if (is_array($menusData)) {
                    [$menuKeyMap, $menuIds, $menuWarnings, $menusImported] = $this->menus->import($menusData, $existingKeys);
                    foreach ($menuWarnings as $warning) {
                        $warnings[] = 'menus: '.$warning;
                    }
                    $importedKeys = array_merge($importedKeys, $menuKeyMap);

                    // Prune demo menus dropped from menus.json since the last import.
                    $staleMenuIds = array_values(array_diff(array_map('intval', $prevMenuIds), $menuIds));
                    if ($staleMenuIds !== []) {
                        $this->menus->deleteMenus($staleMenuIds);
                    }

                    $menusImported ? $steps[] = 'menus' : $skipped[] = 'menus';
                } else {
                    $warnings[] = 'menus file is not valid JSON — skipped.';
                    $skipped[] = 'menus';
                }
            } else {
                $warnings[] = 'menus file is missing or unsafe — skipped.';
                $skipped[] = 'menus';
            }
        }

        // --- Theme-only native files ---
        if ($package->isTheme()) {
            // 2. theme_options (optional)
            if ($package->fileName('theme_options') !== null) {
                $handled['theme_options'] = true;
                $optionsFile = $package->filePath('theme_options');
                $themeSettings = $optionsFile !== null ? $this->readJson($optionsFile) : null;

                if (is_array($themeSettings)) {
                    $options = is_array($themeSettings['options'] ?? null) ? $themeSettings['options'] : [];
                    foreach ($options as $key => $value) {
                        $key = (string) $key;
                        $capture($this->themeOptionKey($owner, $key));
                        if ($key === 'homepage_preset') {
                            $capture($presetKey);
                        }
                        $this->themeOptions->set($key, $value, $owner);
                    }

                    $state = is_array($themeSettings['settings'] ?? null) ? $themeSettings['settings'] : [];
                    foreach ($state as $key => $value) {
                        $capture((string) $key);
                        $this->settings->set((string) $key, $value);
                    }

                    $steps[] = 'theme_options';
                } else {
                    $warnings[] = 'theme_options file is missing or not valid JSON — skipped.';
                    $skipped[] = 'theme_options';
                }
            }

            // 3. Active preset (authoritative, from manifest.preset)
            if ($package->preset !== null) {
                $capture($presetKey);
                $this->settings->set($presetKey, $package->preset, 'string', [
                    'is_public' => true,
                    'autoload' => true,
                    'description' => 'Active homepage preset (demo import)',
                ]);
                $steps[] = 'homepage-preset';
            }

            // 4. homepage layout
            if ($homepageFile !== null) {
                $handled['homepage'] = true;
                // Resolve media import-key refs ({"ref": "key"}) using the imported map.
                $layoutDoc = $this->resolveLayoutMediaRefs($layoutDoc, $importedKeys);

                $resolved = $this->sections->resolve($layoutDoc, null);
                foreach ($resolved->warnings as $warning) {
                    $warnings[] = 'layout: '.$warning;
                }

                $this->settings->set($layoutKey, $layoutDoc, 'array', [
                    'is_public' => true,
                    'autoload' => true,
                    'description' => 'Homepage layout (demo import)',
                ]);
                $steps[] = 'homepage';
            }
        }

        // --- 5. Remaining files → custom handlers (or skipped with a clear reason) ---
        // Ensure the owning plugin's PSR-4 autoload is registered BEFORE resolving
        // any handler classes, so handler resolution does not depend on global boot
        // order. Best-effort and generic — class_exists() below still guards.
        if ($package->isPlugin()) {
            $plugin = $this->extensions->findPlugin($package->owner);
            if ($plugin !== null) {
                try {
                    $this->extensions->ensurePluginAutoload($plugin);
                } catch (\Throwable) {
                    // Autoload registration is best-effort.
                }
            }
        }

        $context = new DemoImportContext($package, $importedKeys, null);
        $handlerRan = false;

        foreach ($package->files as $logical => $filename) {
            if (isset($handled[$logical])) {
                continue;
            }

            $filePath = $package->filePath($logical);
            if ($filePath === null) {
                $warnings[] = "file '{$logical}' ({$filename}) is missing or unsafe — skipped.";
                $skipped[] = $logical;

                continue;
            }

            $handlerClass = $package->handlerClass($logical);
            if ($handlerClass === null) {
                $warnings[] = in_array($logical, self::NATIVE_FILES, true)
                    ? "'{$logical}' is only imported for theme packages — skipped."
                    : "No built-in importer for '{$logical}' and no handler declared — skipped.";
                $skipped[] = $logical;

                continue;
            }

            if (! class_exists($handlerClass)) {
                $warnings[] = "Handler '{$handlerClass}' for '{$logical}' was not found — skipped.";
                $skipped[] = $logical;

                continue;
            }

            $data = $this->readJson($filePath);
            if ($data === null) {
                $warnings[] = "file '{$logical}' is not valid JSON — skipped.";
                $skipped[] = $logical;

                continue;
            }

            try {
                $handler = app($handlerClass);

                if (! $handler instanceof DemoImportHandler) {
                    $warnings[] = "Handler '{$handlerClass}' does not implement DemoImportHandler — skipped.";
                    $skipped[] = $logical;

                    continue;
                }

                $handler->import($package, $data, $context);
                $steps[] = $logical;
                $handlerRan = true;
            } catch (\Throwable $e) {
                $warnings[] = "Handler for '{$logical}' failed: ".$e->getMessage();
                $skipped[] = $logical;
            }
        }

        // Roll up handler-reported outcomes.
        foreach ($context->created() as $created) {
            $steps[] = $created;
        }
        foreach (array_merge($context->warnings(), $context->errors()) as $warning) {
            $warnings[] = $warning;
        }

        // --- Provenance ---
        $this->settings->set($provenanceKey, [
            'batch_id' => $batchId,
            'imported_at' => now()->toIso8601String(),
            'type' => $package->type,
            'owner' => $owner,
            'slug' => $slug,
            'manifest_version' => $package->version,
            'manifest' => $package->manifest,
            'steps' => $steps,
            'handler_ran' => $handlerRan,
            'settings_snapshot' => $settingsSnapshot,
            'layout_snapshot' => $layoutSnapshot,
            'imported_keys' => $importedKeys,
            'imported_menu_ids' => $menuIds,
        ], 'array', [
            'is_public' => false,
            'autoload' => false,
            'description' => 'Demo import provenance',
        ]);

        return ImportResult::ok(
            $owner,
            $slug,
            $batchId,
            $isReimport ? 'Demo re-imported.' : 'Demo imported.',
            $isReimport ? [] : $steps,
            $isReimport ? $steps : [],
            $skipped,
            $warnings,
        );
    }

    /**
     * Reset a demo import: restore the pre-import settings/layout snapshots and
     * drop the provenance entry. Imported media rows are intentionally left in
     * place (assets may be shared). Data written by a custom handler is NOT
     * automatically reverted (a warning is added when a handler ran).
     */
    public function reset(DemoPackage $package): ImportResult
    {
        $owner = $package->owner;
        $slug = $package->slug;

        $provenanceKey = $this->provenanceKey($owner, $slug);
        $provenance = $this->settings->get($provenanceKey);

        if (! is_array($provenance)) {
            return ImportResult::failure($owner, $slug, 'No demo import found to reset.');
        }

        $restored = [];
        $warnings = [];

        $snapshot = is_array($provenance['settings_snapshot'] ?? null) ? $provenance['settings_snapshot'] : [];
        foreach ($snapshot as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $this->restoreKey((string) $key, $entry);
            $restored[] = (string) $key;
        }

        $layoutSnapshot = $provenance['layout_snapshot'] ?? null;
        if (is_array($layoutSnapshot)) {
            $layoutKey = $this->settingKey($owner, 'homepage_layout');
            $this->restoreKey($layoutKey, $layoutSnapshot);
            $restored[] = $layoutKey;
        }

        // Remove only the menus this demo created (by provenance) — never user
        // menus. Items/translations cascade. A seeded location menu, if any,
        // resumes once the demo menu is gone.
        $menuIds = is_array($provenance['imported_menu_ids'] ?? null) ? $provenance['imported_menu_ids'] : [];
        if ($menuIds !== []) {
            foreach ($this->menus->deleteMenus($menuIds) as $deletedId) {
                $restored[] = 'menu:'.$deletedId;
            }
        }

        if (! empty($provenance['handler_ran'])) {
            $warnings[] = 'Data imported by a custom handler was not automatically reverted.';
        }

        $this->settings->forget($provenanceKey);

        return ImportResult::ok(
            $owner,
            $slug,
            is_string($provenance['batch_id'] ?? null) ? $provenance['batch_id'] : '',
            'Demo import reset.',
            [],
            $restored,
            [],
            $warnings,
        );
    }

    /**
     * Scan a {owner}/demo directory for valid packages of the expected type, only
     * accepting a manifest whose declared type/owner matches its location.
     *
     * @param  array<string, DemoPackage>  $out
     */
    private function scan(string $type, string $owner, string $ownerPath, array &$out): void
    {
        $root = $ownerPath.DIRECTORY_SEPARATOR.'demo';

        if (! File::isDirectory($root)) {
            return;
        }

        foreach (File::directories($root) as $path) {
            $manifest = $this->readJson($path.DIRECTORY_SEPARATOR.'manifest.json');

            if (! is_array($manifest)) {
                continue;
            }

            $package = DemoPackage::fromManifest($manifest, $path);

            // Reject a manifest whose declared identity does not match its location.
            if ($package === null || $package->type !== $type || $package->owner !== $owner) {
                continue;
            }

            $out[$package->id()] = $package;
        }
    }

    /**
     * Restore a single setting key from a snapshot entry { existed, value }.
     *
     * @param  array<string, mixed>  $entry
     */
    private function restoreKey(string $key, array $entry): void
    {
        if (! empty($entry['existed'])) {
            $this->settings->set($key, $entry['value'] ?? null);
        } else {
            $this->settings->forget($key);
        }
    }

    // ---------------------------------------------------------------------
    // Media
    // ---------------------------------------------------------------------

    /**
     * Import the bundled assets declared in the media file, idempotently.
     *
     * Returns [ keyMap (key => media id), warnings, anyImported ].
     *
     * @param  array<string, int>  $existingKeys
     * @return array{0: array<string, int>, 1: array<int, string>, 2: bool}
     */
    private function importMedia(string $packageDir, string $mediaFile, array $existingKeys): array
    {
        $data = $this->readJson($mediaFile);

        if (! is_array($data)) {
            return [[], ['media file is not valid JSON — skipped.'], false];
        }

        // Accept { "media": [ ... ] } or a bare top-level list.
        $items = is_array($data['media'] ?? null) ? $data['media'] : (array_is_list($data) ? $data : []);

        $map = [];
        $warnings = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['key'] ?? null;
            $file = $item['file'] ?? null;

            if (! is_string($key) || $key === '') {
                $warnings[] = 'media item is missing a "key" — skipped.';

                continue;
            }

            if (! is_string($file) || $file === '') {
                $warnings[] = "media '{$key}' is missing a \"file\" — skipped.";

                continue;
            }

            $meta = [
                'alt' => is_string($item['alt'] ?? null) ? $item['alt'] : null,
                'title' => is_string($item['title'] ?? null) ? $item['title'] : null,
                'mime' => is_string($item['type'] ?? null) ? $item['type'] : null,
                'width' => is_int($item['width'] ?? null) ? $item['width'] : null,
                'height' => is_int($item['height'] ?? null) ? $item['height'] : null,
                'original_name' => basename($file),
            ];

            // Idempotent re-import: reuse + update the existing media row.
            $existingId = $existingKeys[$key] ?? null;
            if (is_int($existingId) || (is_string($existingId) && ctype_digit($existingId))) {
                $row = Media::find((int) $existingId);
                if ($row !== null) {
                    $row->fill(array_filter([
                        'alt' => $meta['alt'],
                        'title' => $meta['title'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ], static fn ($v) => $v !== null))->save();
                    $map[$key] = (int) $row->id;

                    continue;
                }
            }

            $absolute = $this->resolveAssetPath($packageDir, $file);
            if ($absolute === null) {
                $warnings[] = "media '{$key}' file is missing or outside the demo directory — skipped.";

                continue;
            }

            try {
                $row = $this->media->importFile($absolute, $meta);
                $map[$key] = (int) $row->id;
            } catch (\Throwable $e) {
                $warnings[] = "media '{$key}' could not be imported: ".$e->getMessage();
            }
        }

        return [$map, $warnings, $map !== []];
    }

    /**
     * Resolve an asset path declared in the media file against the package
     * directory, rejecting traversal / escapes.
     */
    private function resolveAssetPath(string $packageDir, string $relative): ?string
    {
        if (str_contains($relative, '..') || str_contains($relative, "\0")) {
            return null;
        }

        if (preg_match('#^([a-zA-Z]:[\\\\/]|[\\\\/])#', $relative) === 1) {
            return null;
        }

        $candidate = $packageDir.DIRECTORY_SEPARATOR.str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);

        if (! File::exists($candidate)) {
            return null;
        }

        $realDir = realpath($packageDir);
        $realFile = realpath($candidate);

        if ($realDir === false || $realFile === false || ! str_starts_with($realFile, $realDir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realFile;
    }

    /**
     * Recursively replace media import-key refs ({"ref": "key"}) with resolved
     * media references ({"kind": "media", "id": N}) using the key map.
     *
     * @param  array<string, int>  $keyMap
     */
    private function resolveLayoutMediaRefs(mixed $node, array $keyMap): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        if (isset($node['ref']) && is_string($node['ref']) && array_key_exists($node['ref'], $keyMap)) {
            return ['kind' => 'media', 'id' => $keyMap[$node['ref']]];
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = $this->resolveLayoutMediaRefs($value, $keyMap);
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    private function preflight(DemoPackage $package): array
    {
        $errors = [];
        $requires = $package->requires;

        if ($package->isTheme()) {
            $requiredTheme = $requires['theme'] ?? null;
            if (is_string($requiredTheme) && $requiredTheme !== '' && $requiredTheme !== $package->owner) {
                $errors[] = "Demo requires theme '{$requiredTheme}', but it belongs to '{$package->owner}'.";
            }
        }

        $requiredCms = $requires['cms'] ?? null;
        if (is_string($requiredCms) && $requiredCms !== '' && ! $this->cmsVersionSatisfies($requiredCms)) {
            $errors[] = "Demo requires CMS version '{$requiredCms}' (current: ".CmsInfo::VERSION.').';
        }

        $requiredPlugins = is_array($requires['plugins'] ?? null) ? $requires['plugins'] : [];
        foreach ($requiredPlugins as $plugin) {
            if (! $this->extensions->isPluginActive((string) $plugin)) {
                $errors[] = "Demo requires the active plugin '{$plugin}'.";
            }
        }

        return $errors;
    }

    /**
     * Compare a constraint (e.g. ">=1.0.0", "1.0.0") against the CMS version.
     * The current version's pre-release suffix is stripped so e.g.
     * "1.0.0-beta.6" satisfies ">=1.0.0".
     */
    private function cmsVersionSatisfies(string $constraint): bool
    {
        $current = (string) preg_replace('/-.*$/', '', CmsInfo::VERSION);

        if (preg_match('/^\s*(>=|<=|>|<|=)?\s*(.+?)\s*$/', $constraint, $matches) === 1) {
            $operator = $matches[1] !== '' ? $matches[1] : '>=';
            $version = $matches[2];

            return version_compare($current, $version, $operator);
        }

        return true;
    }

    private function isLayoutDocument(mixed $doc): bool
    {
        return is_array($doc) && isset($doc['sections']) && is_array($doc['sections']);
    }

    // ---------------------------------------------------------------------
    // Keys
    // ---------------------------------------------------------------------

    private function settingKey(string $owner, string $name): string
    {
        return 'theme.'.$owner.'.'.$name;
    }

    private function themeOptionKey(string $owner, string $key): string
    {
        return 'theme_options.'.$owner.'.'.$key;
    }

    private function provenanceKey(string $owner, string $slug): string
    {
        return 'demo.imports.'.$owner.'.'.$slug;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        if (! File::exists($file)) {
            return null;
        }

        $data = json_decode((string) File::get($file), true);

        return is_array($data) ? $data : null;
    }
}
