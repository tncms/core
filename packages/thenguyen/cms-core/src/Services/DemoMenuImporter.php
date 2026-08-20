<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Models\Menu;

/**
 * Imports the `menus` logical file of a demo package (theme-architecture 16,
 * Phase 7A2). It is a native companion to {@see DemoImporter} — menus are core
 * models (cms_menus / cms_menu_items and their translations), so the importer
 * owns this rather than delegating to a per-package handler.
 *
 * Contract (menus.json):
 *   { "menus": [ {
 *       "key": "company.menu.header",      // stable import key (identity)
 *       "location": "header",              // render location
 *       "name": { "vi": "…", "en": "…" },  // localized menu name (or plain string)
 *       "items": [ {
 *           "key": "company.menu.header.home",
 *           "label": { "vi": "…", "en": "…" },
 *           "url": "/",
 *           "target": "_self",             // optional, default _self
 *           "type": "custom",              // optional, default custom
 *           "icon": "book-open",           // optional, presentational icon token
 *           "meta": {                       // optional, Phase 11B appearance
 *               "display": "mega",         //   normal | dropdown | mega
 *               "mega_columns": 4,          //   2..6
 *               "mega_width": "wide",       //   content | wide | full
 *               "badge": "NEW",             //   optional short badge
 *               "description": "…"          //   optional description
 *           },
 *           "children": [ … ]              // optional, nested items
 *       } ]
 *   } ] }
 *
 * Idempotency: a menu is identified by its import `key` (resolved from the prior
 * imported-keys map, else by a deterministic slug). Re-import REUSES the menu row
 * (its id — and therefore its location wiring — is stable) and rebuilds its items
 * wholesale, so repeated imports never duplicate menus or items. The demo fully
 * owns the menus it creates; it never reads or mutates menus it did not create,
 * so unrelated user menus are untouched.
 *
 * Precedence: demo menus are saved with a sort_order that sorts before the
 * install-seeded location menus (which default to 0), so the imported menu is the
 * one a location renders — without deleting or modifying the seeded/user menu.
 * On {@see reset()} the demo menu is removed and the seeded menu resumes.
 */
class DemoMenuImporter
{
    /**
     * Demo menus sort before install-seeded location menus (sort_order 0) so the
     * imported menu wins {@see MenuManager::getMenuByLocation()} without touching
     * any other menu at the same location.
     */
    private const DEMO_SORT_ORDER = -1;

    public function __construct(private readonly LanguageManager $languages) {}

    /**
     * Import every menu declared in a decoded menus.json, idempotently.
     *
     * @param  array<string, mixed>  $data  the decoded menus.json
     * @param  array<string, int>  $existingKeys  prior imported_keys (menu/item key => id)
     * @return array{0: array<string, int>, 1: array<int, int>, 2: array<int, string>, 3: bool}
     *                                                                                          [ keyMap (key => id), menuIds, warnings, anyImported ]
     */
    public function import(array $data, array $existingKeys = []): array
    {
        $menus = is_array($data['menus'] ?? null) ? $data['menus'] : [];
        $keyMap = [];
        $menuIds = [];
        $warnings = [];

        foreach ($menus as $spec) {
            if (! is_array($spec)) {
                $warnings[] = 'a menu entry is not an object — skipped.';

                continue;
            }

            $key = $spec['key'] ?? null;
            $location = $spec['location'] ?? null;

            if (! is_string($key) || $key === '') {
                $warnings[] = 'a menu is missing a "key" — skipped.';

                continue;
            }

            if (! is_string($location) || $location === '') {
                $warnings[] = "menu '{$key}' is missing a \"location\" — skipped.";

                continue;
            }

            $menu = $this->resolveMenu($key, $existingKeys);

            $menu->fill([
                'slug' => $menu->slug !== '' && $menu->slug !== null ? $menu->slug : $this->slugFor($key),
                'location' => $location,
                'status' => 'active',
                'is_system' => false,
                'sort_order' => self::DEMO_SORT_ORDER,
            ]);
            $menu->save();

            $this->writeTranslations($menu->translations(), $spec['name'] ?? null, 'name', (int) $menu->id, 'Menu');

            $keyMap[$key] = (int) $menu->id;
            $menuIds[] = (int) $menu->id;

            // The demo owns its menu's items, so rebuild them wholesale: idempotent,
            // and the menu id (location wiring) stays stable across re-imports.
            $this->rebuildItems($menu, is_array($spec['items'] ?? null) ? $spec['items'] : [], $keyMap);
        }

        return [$keyMap, $menuIds, $warnings, $menus !== []];
    }

    /**
     * Hard-delete the given demo menus (and their items/translations via FK
     * cascade). Used by {@see DemoImporter::reset()} and to prune menus dropped
     * from menus.json on re-import. Missing ids are ignored.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int> the ids actually deleted
     */
    public function deleteMenus(array $ids): array
    {
        $deleted = [];

        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                continue;
            }

            $menu = Menu::withTrashed()->find((int) $id);

            if ($menu === null) {
                continue;
            }

            DB::transaction(function () use ($menu, &$deleted): void {
                foreach ($menu->items()->withTrashed()->get() as $item) {
                    $item->forceDelete();
                }
                $id = (int) $menu->id;
                $menu->forceDelete();
                $deleted[] = $id;
            });
        }

        return $deleted;
    }

    /**
     * Resolve a menu by its prior imported id (re-import), then by its
     * deterministic slug (provenance lost but menu still present), else a fresh
     * unsaved instance.
     *
     * @param  array<string, int>  $existingKeys
     */
    private function resolveMenu(string $key, array $existingKeys): Menu
    {
        $id = $existingKeys[$key] ?? null;

        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $found = Menu::withTrashed()->find((int) $id);
            if ($found !== null) {
                $found->restore();

                return $found;
            }
        }

        $slug = $this->slugFor($key);

        return Menu::withTrashed()->where('slug', $slug)->first() ?? new Menu(['slug' => $slug]);
    }

    private function slugFor(string $key): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $key), '-'));

        return $slug !== '' ? $slug : 'menu';
    }

    /**
     * Replace a menu's items with the declared tree (recursively).
     *
     * @param  array<int, mixed>  $items
     * @param  array<string, int>  $keyMap
     */
    private function rebuildItems(Menu $menu, array $items, array &$keyMap): void
    {
        DB::transaction(function () use ($menu, $items, &$keyMap): void {
            foreach ($menu->items()->withTrashed()->get() as $existing) {
                $existing->forceDelete();
            }

            $this->createItems($menu, $items, null, $keyMap);
        });
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array<string, int>  $keyMap
     */
    private function createItems(Menu $menu, array $items, ?int $parentId, array &$keyMap): void
    {
        $order = 0;

        foreach ($items as $spec) {
            if (! is_array($spec)) {
                continue;
            }

            $item = $menu->items()->create([
                'parent_id' => $parentId,
                'type' => is_string($spec['type'] ?? null) && $spec['type'] !== '' ? $spec['type'] : 'custom',
                'url' => is_string($spec['url'] ?? null) && $spec['url'] !== '' ? $spec['url'] : '#',
                'target' => is_string($spec['target'] ?? null) && $spec['target'] !== '' ? $spec['target'] : '_self',
                'icon' => is_string($spec['icon'] ?? null) && $spec['icon'] !== '' ? $spec['icon'] : null,
                'meta' => $this->metaFor($spec),
                'sort_order' => $order,
                'is_active' => true,
            ]);

            $this->writeTranslations($item->translations(), $spec['label'] ?? null, 'title', (int) $item->id, 'Item');

            $key = $spec['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $keyMap[$key] = (int) $item->id;
            }

            $children = $spec['children'] ?? null;
            if (is_array($children) && $children !== []) {
                $this->createItems($menu, $children, (int) $item->id, $keyMap);
            }

            $order++;
        }
    }

    /**
     * Extract presentational metadata (Phase 11B) from an item spec, keeping only
     * the known JSON keys. Values are normalized on read by
     * {@see \TheNguyen\CMS\Models\MenuItem::resolvedMeta()}, so a malformed demo
     * entry never breaks rendering. Returns null when nothing is declared.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function metaFor(array $spec): ?array
    {
        $meta = is_array($spec['meta'] ?? null) ? $spec['meta'] : [];
        $out = [];

        foreach (['display', 'mega_columns', 'mega_width', 'mega_source', 'mega_widget_area', 'badge', 'description'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out[$key] = $meta[$key];
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * Write one translation row per locale from a localized map ({locale: text}),
     * a plain string (default locale), or a deterministic placeholder.
     */
    private function writeTranslations(HasMany $relation, mixed $value, string $column, int $ownerId, string $placeholder): void
    {
        $map = [];

        if (is_array($value)) {
            foreach ($value as $locale => $text) {
                if (is_string($locale) && $locale !== '' && is_string($text)) {
                    $map[$locale] = $text;
                }
            }
        } elseif (is_string($value) && $value !== '') {
            $map[$this->languages->defaultCode()] = $value;
        }

        if ($map === []) {
            $map[$this->languages->defaultCode()] = $placeholder.' #'.$ownerId;
        }

        foreach ($map as $locale => $text) {
            $relation->updateOrCreate(
                ['locale' => $locale],
                [$column => $text !== '' ? $text : ($placeholder.' #'.$ownerId)],
            );
        }
    }
}
