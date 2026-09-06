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
 *   - `pages`         → cms_contents Pages with localized translations and
 *                       page-template identifiers (CORE-THEME-2, theme only);
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
    private const NATIVE_FILES = ['media', 'theme_options', 'homepage', 'menus', 'pages', 'categories', 'tags', 'posts'];

    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ThemeOptionManager $themeOptions,
        private readonly SectionResolver $sections,
        private readonly MediaManager $media,
        private readonly ExtensionManager $extensions,
        private readonly DemoMenuImporter $menus,
        private readonly DemoPageImporter $pages,
        private readonly DemoCategoryImporter $categories,
        private readonly DemoTagImporter $tags,
        private readonly DemoPostImporter $posts,
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

        // Pages the previous import created — pruned if dropped from pages.json.
        $prevPageIds = $isReimport && is_array($existing['imported_page_ids'] ?? null)
            ? $existing['imported_page_ids']
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

        // EG-9 — one coherent symbol resolver per import run; media registers now,
        // categories/tags/posts register themselves as they import. Deterministic
        // source fingerprints and per-type id lists roll into provenance.
        $resolver = new DemoSymbolResolver;
        $fingerprints = [];
        $categoryIds = [];
        $tagIds = [];
        $postIds = [];

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

        // EG-9 — register imported media under the `media` namespace so
        // categories/tags/posts can resolve `media:{key}` refs (no path guessing).
        foreach ($importedKeys as $mediaKey => $mediaId) {
            if (is_string($mediaKey) && (is_int($mediaId) || (is_string($mediaId) && ctype_digit($mediaId)))) {
                $resolver->register('media', $mediaKey, (int) $mediaId);
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
        $pageIds = [];

        if ($package->isTheme()) {
            // EG-9 dependency order: media → categories → tags → pages → posts.
            // 1a. categories — hierarchical taxonomy terms (symbolic parents).
            if ($package->fileName('categories') !== null) {
                $handled['categories'] = true;
                $file = $package->filePath('categories');
                $data = $file !== null ? $this->readJson($file) : null;

                if (is_array($data)) {
                    [$map, $categoryIds, $fp, $w, $conflicts, $imported] = $this->categories->import($data, $resolver, $existingKeys);
                    $importedKeys = array_merge($importedKeys, $map);
                    $fingerprints = array_merge($fingerprints, $fp);
                    $this->collect($warnings, 'categories', $w, $conflicts);
                    $imported ? $steps[] = 'categories' : $skipped[] = 'categories';
                } else {
                    $warnings[] = 'categories file is missing or not valid JSON — skipped.';
                    $skipped[] = 'categories';
                }
            }

            // 1b. tags — flat taxonomy terms.
            if ($package->fileName('tags') !== null) {
                $handled['tags'] = true;
                $file = $package->filePath('tags');
                $data = $file !== null ? $this->readJson($file) : null;

                if (is_array($data)) {
                    [$map, $tagIds, $fp, $w, $conflicts, $imported] = $this->tags->import($data, $resolver, $existingKeys);
                    $importedKeys = array_merge($importedKeys, $map);
                    $fingerprints = array_merge($fingerprints, $fp);
                    $this->collect($warnings, 'tags', $w, $conflicts);
                    $imported ? $steps[] = 'tags' : $skipped[] = 'tags';
                } else {
                    $warnings[] = 'tags file is missing or not valid JSON — skipped.';
                    $skipped[] = 'tags';
                }
            }

            // 1c. pages (CORE-THEME-2) — create/update the declared Pages with
            // their page-template identifiers; Core owns every write.
            if ($package->fileName('pages') !== null) {
                $handled['pages'] = true;
                $pagesFile = $package->filePath('pages');

                if ($pagesFile !== null) {
                    $pagesData = $this->readJson($pagesFile);

                    if (is_array($pagesData)) {
                        [$pageKeyMap, $pageIds, $homepagePageId, $pageWarnings, $pagesImported] =
                            $this->pages->import($pagesData, $existingKeys, $owner);

                        foreach ($pageWarnings as $warning) {
                            $warnings[] = 'pages: '.$warning;
                        }
                        $importedKeys = array_merge($importedKeys, $pageKeyMap);

                        // Prune demo pages dropped from pages.json since the last import.
                        $stalePageIds = array_values(array_diff(array_map('intval', $prevPageIds), $pageIds));
                        if ($stalePageIds !== []) {
                            $this->pages->deleteImported($stalePageIds);
                        }

                        // Static-homepage assignment (snapshot-captured → reset restores).
                        if ($homepagePageId !== null) {
                            $capture('reading.homepage_display');
                            $capture('reading.homepage_page_id');
                            $this->settings->set('reading.homepage_display', 'static_page');
                            $this->settings->set('reading.homepage_page_id', $homepagePageId);
                            $steps[] = 'homepage-page';
                        }

                        $pagesImported ? $steps[] = 'pages' : $skipped[] = 'pages';
                    } else {
                        $warnings[] = 'pages file is not valid JSON — skipped.';
                        $skipped[] = 'pages';
                    }
                } else {
                    $warnings[] = 'pages file is missing or unsafe — skipped.';
                    $skipped[] = 'pages';
                }
            }

            // 1e. posts — Content(type=post) with taxonomy refs, featured media
            // and safe author mapping (imported last so all refs resolve).
            if ($package->fileName('posts') !== null) {
                $handled['posts'] = true;
                $file = $package->filePath('posts');
                $data = $file !== null ? $this->readJson($file) : null;

                if (is_array($data)) {
                    [$map, $postIds, $fp, $w, $conflicts, $imported] = $this->posts->import($data, $resolver, $existingKeys);
                    $importedKeys = array_merge($importedKeys, $map);
                    $fingerprints = array_merge($fingerprints, $fp);
                    $this->collect($warnings, 'posts', $w, $conflicts);
                    $imported ? $steps[] = 'posts' : $skipped[] = 'posts';
                } else {
                    $warnings[] = 'posts file is missing or not valid JSON — skipped.';
                    $skipped[] = 'posts';
                }
            }

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
            'imported_page_ids' => $pageIds,
            // EG-9 — per-type imported ids + deterministic source fingerprints
            // (symbolic key => sha256 of normalized declarative source). These
            // identify created vs safely-reused objects and owned-unchanged vs
            // owned-source-changed; provenance-driven rollback lands in a later phase.
            'imported_category_ids' => $categoryIds,
            'imported_tag_ids' => $tagIds,
            'imported_post_ids' => $postIds,
            'fingerprints' => $fingerprints,
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

        // Remove only the pages this demo created (by provenance) — never user
        // pages. The pre-import homepage settings are restored by the settings
        // snapshot above.
        $pageIds = is_array($provenance['imported_page_ids'] ?? null) ? $provenance['imported_page_ids'] : [];
        if ($pageIds !== []) {
            foreach ($this->pages->deleteImported($pageIds) as $deletedId) {
                $restored[] = 'page:'.$deletedId;
            }
        }

        // EG-9 — remove importer-owned posts FIRST (their post↔term relations
        // cascade), so any taxonomy still referenced afterwards is external (user)
        // content preserved by the shared-taxonomy guard below.
        $postIds = is_array($provenance['imported_post_ids'] ?? null) ? $provenance['imported_post_ids'] : [];
        if ($postIds !== []) {
            foreach ($this->posts->deleteImported($postIds) as $deletedId) {
                $restored[] = 'post:'.$deletedId;
            }
        }

        // EG-9 — remove importer-owned categories/tags by provenance id, but never
        // a term a user object still references (shared-taxonomy safety).
        $categoryIds = is_array($provenance['imported_category_ids'] ?? null) ? $provenance['imported_category_ids'] : [];
        if ($categoryIds !== []) {
            [$deletedCats, $preservedCats] = $this->categories->deleteImported($categoryIds);
            foreach ($deletedCats as $deletedId) {
                $restored[] = 'category:'.$deletedId;
            }
            foreach ($preservedCats as $preservedId) {
                $warnings[] = "category #{$preservedId} is still referenced by other content — preserved (not deleted).";
            }
        }

        $tagIds = is_array($provenance['imported_tag_ids'] ?? null) ? $provenance['imported_tag_ids'] : [];
        if ($tagIds !== []) {
            [$deletedTags, $preservedTags] = $this->tags->deleteImported($tagIds);
            foreach ($deletedTags as $deletedId) {
                $restored[] = 'tag:'.$deletedId;
            }
            foreach ($preservedTags as $preservedId) {
                $warnings[] = "tag #{$preservedId} is still referenced by other content — preserved (not deleted).";
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
     * Read-only import preview (dry-run, CORE-THEME-2): reports what an import
     * WOULD create, update, set or skip — without a single write. Covers the
     * native files; a declared custom handler is listed as opaque.
     *
     * @return array{
     *   ok: bool, reimport: bool,
     *   preflight: array<int, string>,
     *   actions: array<int, array{file: string, action: string, detail: string}>,
     *   warnings: array<int, string>
     * }
     */
    public function preview(DemoPackage $package): array
    {
        $owner = $package->owner;
        $preflight = $this->preflight($package);

        $existing = $this->settings->get($this->provenanceKey($owner, $package->slug));
        $isReimport = is_array($existing);
        $existingKeys = $isReimport && is_array($existing['imported_keys'] ?? null) ? $existing['imported_keys'] : [];
        $priorFingerprints = $isReimport && is_array($existing['fingerprints'] ?? null) ? $existing['fingerprints'] : [];

        $actions = [];
        $warnings = [];
        $conflicts = [];

        // media — new rows vs reused rows per import key.
        if ($package->fileName('media') !== null) {
            $data = ($f = $package->filePath('media')) !== null ? $this->readJson($f) : null;
            $items = is_array($data) ? (is_array($data['media'] ?? null) ? $data['media'] : (array_is_list($data) ? $data : [])) : [];

            foreach ($items as $item) {
                $key = is_array($item) && is_string($item['key'] ?? null) ? $item['key'] : null;
                if ($key === null) {
                    continue;
                }
                $actions[] = [
                    'file' => 'media', 'detail' => $key,
                    'action' => isset($existingKeys[$key]) ? 'update' : 'create',
                ];
            }
        }

        // menus — create vs update per menu key.
        if ($package->fileName('menus') !== null) {
            $data = ($f = $package->filePath('menus')) !== null ? $this->readJson($f) : null;
            $items = is_array($data) && is_array($data['menus'] ?? null) ? $data['menus'] : [];

            foreach ($items as $item) {
                $key = is_array($item) && is_string($item['key'] ?? null) ? $item['key'] : null;
                if ($key === null) {
                    continue;
                }
                $actions[] = [
                    'file' => 'menus', 'detail' => $key,
                    'action' => isset($existingKeys[$key]) ? 'update' : 'create',
                ];
            }
        }

        if ($package->isTheme()) {
            // pages — create vs update per page key; undeclared template = warning.
            if ($package->fileName('pages') !== null) {
                $data = ($f = $package->filePath('pages')) !== null ? $this->readJson($f) : null;
                $items = is_array($data) && is_array($data['pages'] ?? null) ? $data['pages'] : [];
                $declared = app('cms.page_templates')->templatesFor($owner);

                foreach ($items as $item) {
                    if (! is_array($item) || ! is_string($item['key'] ?? null)) {
                        continue;
                    }
                    $key = $item['key'];
                    $actions[] = [
                        'file' => 'pages', 'detail' => $key,
                        'action' => isset($existingKeys['page:'.$key]) ? 'update' : 'create',
                    ];

                    $template = $item['template'] ?? null;
                    if (is_string($template) && trim($template) !== '' && ! isset($declared[trim($template)])) {
                        $warnings[] = "pages: template '".trim($template)."' is not declared by theme '{$owner}' — page '{$key}' would import without a template.";
                    }
                    if (($item['homepage'] ?? false) === true) {
                        $actions[] = ['file' => 'pages', 'action' => 'set', 'detail' => "homepage → '{$key}'"];
                    }
                }
            }

            // EG-9 — categories / tags / posts forecast (zero writes). A plan
            // resolver seeded from prior imported keys + this run's declared keys
            // lets forward/cross refs resolve during planning.
            $planResolver = $this->buildPlanResolver($package, $existingKeys);

            if ($package->fileName('categories') !== null && ($f = $package->filePath('categories')) !== null && is_array($d = $this->readJson($f))) {
                [$a, $c] = $this->categories->plan($d, $planResolver, $existingKeys, $priorFingerprints);
                $actions = array_merge($actions, $a);
                $conflicts = array_merge($conflicts, $c);
            }

            if ($package->fileName('tags') !== null && ($f = $package->filePath('tags')) !== null && is_array($d = $this->readJson($f))) {
                [$a, $c] = $this->tags->plan($d, $existingKeys, $priorFingerprints);
                $actions = array_merge($actions, $a);
                $conflicts = array_merge($conflicts, $c);
            }

            if ($package->fileName('posts') !== null && ($f = $package->filePath('posts')) !== null && is_array($d = $this->readJson($f))) {
                [$a, $c] = $this->posts->plan($d, $planResolver, $existingKeys, $priorFingerprints);
                $actions = array_merge($actions, $a);
                $conflicts = array_merge($conflicts, $c);
            }

            if ($package->fileName('theme_options') !== null) {
                $data = ($f = $package->filePath('theme_options')) !== null ? $this->readJson($f) : null;
                $count = is_array($data) ? count(is_array($data['options'] ?? null) ? $data['options'] : []) : 0;
                $actions[] = ['file' => 'theme_options', 'action' => 'set', 'detail' => $count.' option(s)'];
            }

            if ($package->preset !== null) {
                $actions[] = ['file' => 'manifest', 'action' => 'set', 'detail' => "homepage preset '{$package->preset}'"];
            }

            if ($package->fileName('homepage') !== null) {
                $actions[] = [
                    'file' => 'homepage', 'action' => $this->settings->has($this->settingKey($owner, 'homepage_layout')) ? 'update' : 'set',
                    'detail' => 'homepage layout',
                ];
            }
        }

        // Anything else → declared handler (opaque) or skip.
        foreach ($package->files as $logical => $filename) {
            if (in_array($logical, self::NATIVE_FILES, true)) {
                continue;
            }
            $handler = $package->handlerClass($logical);
            $actions[] = [
                'file' => $logical,
                'action' => $handler !== null ? 'handler' : 'skip',
                'detail' => $handler !== null ? 'custom handler (changes not previewable)' : 'no importer/handler',
            ];
        }

        return [
            'ok' => $preflight === [],
            'reimport' => $isReimport,
            'preflight' => $preflight,
            'actions' => $actions,
            'warnings' => $warnings,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Build a read-only plan resolver: prior imported symbolic keys plus every
     * symbolic key DECLARED in this run's media/categories/tags/posts files
     * (registered with a sentinel id) so preview can classify cross/forward refs
     * as resolvable vs missing without importing anything.
     *
     * @param  array<string, int|string>  $existingKeys
     */
    private function buildPlanResolver(DemoPackage $package, array $existingKeys): DemoSymbolResolver
    {
        $resolver = DemoSymbolResolver::fromImportedKeys($existingKeys);

        $sources = [
            'media' => ['media', 'media'],
            'categories' => ['categories', 'category'],
            'tags' => ['tags', 'tag'],
            'posts' => ['posts', 'post'],
        ];

        foreach ($sources as $logical => [$listKey, $namespace]) {
            if ($package->fileName($logical) === null) {
                continue;
            }

            $file = $package->filePath($logical);
            $data = $file !== null ? $this->readJson($file) : null;

            if (! is_array($data)) {
                continue;
            }

            $items = is_array($data[$listKey] ?? null) ? $data[$listKey] : (array_is_list($data) ? $data : []);

            foreach ($items as $item) {
                if (is_array($item) && is_string($item['key'] ?? null) && trim($item['key']) !== '') {
                    // Sentinel id (1): "would exist after apply"; a real prior id is
                    // never overwritten (register() keeps the first registration).
                    $resolver->register($namespace, trim($item['key']), 1);
                }
            }
        }

        return $resolver;
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
     * Roll an EG-9 importer's warnings and classified conflicts into the shared
     * warnings list under a logical prefix. Conflicts keep their class + symbolic
     * key so the outcome is never a flat "already exists".
     *
     * @param  array<int, string>  $warnings  (by reference) the shared list
     * @param  array<int, string>  $importerWarnings
     * @param  array<int, array{key: string, class: string}>  $conflicts
     */
    private function collect(array &$warnings, string $prefix, array $importerWarnings, array $conflicts): void
    {
        foreach ($importerWarnings as $warning) {
            $warnings[] = $prefix.': '.$warning;
        }

        foreach ($conflicts as $conflict) {
            $warnings[] = $prefix.': ['.$conflict['class'].'] '.$conflict['key'];
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
