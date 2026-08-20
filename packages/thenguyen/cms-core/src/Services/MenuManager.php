<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Models\Menu;
use TheNguyen\CMS\Models\MenuItem;

class MenuManager
{
    public function __construct(
        private readonly SlugManager $slugManager,
        private readonly MenuMegaDataProvider $megaData,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createMenu(array $data): Menu
    {
        return DB::transaction(function () use ($data): Menu {
            $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());
            $name = (string) ($data['name'] ?? '');

            $menu = Menu::query()->create([
                'slug' => $this->uniqueMenuSlug($data['slug'] ?? null, $name, $locale),
                'location' => $data['location'] ?? null,
                'status' => $data['status'] ?? 'active',
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_system' => (bool) ($data['is_system'] ?? false),
            ]);

            $this->upsertMenuTranslation($menu, $locale, $name, $data['description'] ?? null);

            return $menu->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateMenu(Menu $menu, array $data): Menu
    {
        return DB::transaction(function () use ($menu, $data): Menu {
            $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());
            $name = (string) ($data['name'] ?? $menu->displayName($locale));

            $attributes = [
                'location' => array_key_exists('location', $data) ? $data['location'] : $menu->location,
                'status' => $data['status'] ?? $menu->status,
                'sort_order' => array_key_exists('sort_order', $data)
                    ? (int) $data['sort_order']
                    : $menu->sort_order,
            ];

            // Only regenerate the slug when one is explicitly provided.
            if (! empty($data['slug'])) {
                $attributes['slug'] = $this->uniqueMenuSlug($data['slug'], $name, $locale, $menu->id);
            }

            $menu->fill($attributes);
            $menu->save();

            $this->upsertMenuTranslation($menu, $locale, $name, $data['description'] ?? null);

            return $menu->refresh();
        });
    }

    public function deleteMenu(Menu $menu): bool
    {
        return DB::transaction(static fn (): bool => (bool) $menu->delete());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createItem(Menu $menu, array $data): MenuItem
    {
        return DB::transaction(function () use ($menu, $data): MenuItem {
            $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());

            $item = $menu->items()->create($this->itemAttributes($data));

            $this->upsertItemTranslation(
                $item,
                $locale,
                (string) ($data['title'] ?? ''),
                $this->localeUrl($item, $data),
            );

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(MenuItem $item, array $data): MenuItem
    {
        return DB::transaction(function () use ($item, $data): MenuItem {
            $locale = (string) ($data['locale'] ?? app('cms.language')->defaultCode());

            $item->fill($this->itemAttributes($data, $item));
            $item->save();

            if (array_key_exists('title', $data) || array_key_exists('url', $data)) {
                $this->upsertItemTranslation(
                    $item,
                    $locale,
                    (string) ($data['title'] ?? $item->displayTitle($locale)),
                    $this->localeUrl($item, $data),
                );
            }

            return $item->refresh();
        });
    }

    public function deleteItem(MenuItem $item): bool
    {
        return DB::transaction(static fn (): bool => (bool) $item->delete());
    }

    public function getMenuBySlug(string $slug, string $locale = 'vi'): ?Menu
    {
        return Menu::query()
            ->where('slug', $slug)
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->first();
    }

    public function getMenuByLocation(string $location, string $locale = 'vi'): ?Menu
    {
        return Menu::query()
            ->where('location', $location)
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->first();
    }

    /**
     * Build a nested array tree of the menu's active items for the locale.
     *
     * @return array<int, array{item: MenuItem, title: string, url: string, children: array, meta: array<string, mixed>}>
     */
    public function tree(Menu $menu, string $locale = 'vi'): array
    {
        $items = $menu->items()
            ->where('is_active', true)
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->orderBy('sort_order')
            ->get();

        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item->parent_id ?? 0][] = $item;
        }

        return $this->buildTree($byParent, 0, $locale);
    }

    /**
     * Set sort_order on the menu's items to match the given id ordering.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorderItems(Menu $menu, array $orderedIds): void
    {
        DB::transaction(function () use ($menu, $orderedIds): void {
            $position = 0;
            foreach ($orderedIds as $id) {
                $menu->items()->whereKey((int) $id)->update(['sort_order' => $position]);
                $position++;
            }
        });
    }

    /**
     * @param  array<int, array<int, MenuItem>>  $byParent
     * @return array<int, array{item: MenuItem, title: string, url: string, children: array, meta: array<string, mixed>}>
     */
    private function buildTree(array $byParent, int $parentId, string $locale): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $item) {
            $meta = $item->resolvedMeta($locale);

            $node = [
                'item' => $item,
                'title' => $item->displayTitle($locale),
                'url' => $item->resolvedUrl($locale),
                'children' => $this->buildTree($byParent, $item->id, $locale),
                'meta' => $meta,
            ];

            // Post-driven mega sources get their card list resolved here (in core,
            // never in Blade). Non-dynamic sources (children/widget_area) carry no
            // `mega` payload and keep their 11B/11C rendering path unchanged.
            if ($meta['display'] === 'mega'
                && in_array($meta['mega_source'], MenuItem::MEGA_DYNAMIC_SOURCES, true)) {
                $node['mega'] = $this->megaData->resolve($meta, $locale);
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Normalise menu-item attributes according to the item type.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function itemAttributes(array $data, ?MenuItem $current = null): array
    {
        $type = (string) ($data['type'] ?? $current->type ?? 'custom');

        [$referenceType, $referenceId, $url] = $this->resolveReference($type, $data, $current);

        return [
            'parent_id' => array_key_exists('parent_id', $data)
                ? ($data['parent_id'] !== null ? (int) $data['parent_id'] : null)
                : $current?->parent_id,
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'url' => $url,
            'target' => $data['target'] ?? $current->target ?? '_self',
            'css_class' => $data['css_class'] ?? $current?->css_class,
            'icon' => $data['icon'] ?? $current?->icon,
            'meta' => $this->metaInput($data, $current),
            'sort_order' => array_key_exists('sort_order', $data)
                ? (int) $data['sort_order']
                : ($current->sort_order ?? 0),
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($current->is_active ?? true),
        ];
    }

    /**
     * Merge presentational metadata (Phase 11B) from the payload over any
     * existing meta. Only the known JSON keys are accepted; `icon` keeps its
     * dedicated column. Values are stored as-given and normalized on read by
     * {@see MenuItem::resolvedMeta()}, so invalid input never breaks rendering.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function metaInput(array $data, ?MenuItem $current): ?array
    {
        $meta = is_array($current?->meta) ? $current->meta : [];

        $keys = [
            'display', 'mega_columns', 'mega_width', 'mega_source', 'mega_widget_area',
            'mega_limit', 'mega_order_by', 'mega_category_ids', 'mega_tag_ids', 'mega_post_ids',
            'badge', 'description',
        ];

        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $meta[$key] = $data[$key];
            }
        }

        return $meta === [] ? null : $meta;
    }

    /**
     * Resolve the stable reference fields stored on the parent item.
     *
     * The parent NEVER stores a locale-specific URL: entity items have no URL
     * (it is computed per locale from the localized slug), and custom URLs live
     * per-locale in cms_menu_item_translations. The shared column is preserved
     * as-is (legacy fallback) and never overwritten with one locale's value.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function resolveReference(string $type, array $data, ?MenuItem $current): array
    {
        $referenceId = array_key_exists('reference_id', $data)
            ? ($data['reference_id'] !== null ? (int) $data['reference_id'] : null)
            : $current?->reference_id;

        return match ($type) {
            'page', 'post' => ['content', $referenceId, null],
            'category', 'tag' => ['term', $referenceId, null],
            default => [null, null, $current?->url], // custom: keep legacy shared URL untouched
        };
    }

    /**
     * The locale-specific custom URL to persist on the item's translation, or
     * null for entity-linked items (whose URL is derived from the slug).
     *
     * @param  array<string, mixed>  $data
     */
    private function localeUrl(MenuItem $item, array $data): ?string
    {
        if ($item->type !== 'custom') {
            return null;
        }

        $url = array_key_exists('url', $data) ? $data['url'] : null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function upsertMenuTranslation(Menu $menu, string $locale, string $name, ?string $description): void
    {
        $menu->translations()->updateOrCreate(
            ['locale' => $locale],
            [
                'name' => $name !== '' ? $name : ('Menu #'.$menu->id),
                'description' => $description,
            ],
        );
    }

    /**
     * Upsert the selected locale's translation only — other locales' title and
     * URL are never touched. Custom URLs are stored per-locale here; entity
     * items leave the column null (URL is derived from the localized slug).
     */
    private function upsertItemTranslation(MenuItem $item, string $locale, string $title, ?string $url): void
    {
        $values = ['title' => $title !== '' ? $title : ('Item #'.$item->id)];

        if ($item->type === 'custom') {
            $values['url'] = $url;
        }

        $item->translations()->updateOrCreate(
            ['locale' => $locale],
            $values,
        );
    }

    /**
     * Build a unique cms_menus.slug, generating from the name when empty.
     */
    private function uniqueMenuSlug(?string $rawSlug, string $name, string $locale, ?int $ignoreId = null): string
    {
        $rawSlug = is_string($rawSlug) ? trim($rawSlug) : '';
        $source = $rawSlug !== '' ? $rawSlug : ($name !== '' ? $name : 'menu');

        $base = $this->slugManager->generate($source, $locale);
        $base = $base !== '' ? $base : 'menu';

        $candidate = $base;
        $i = 2;

        while ($this->menuSlugExists($candidate, $ignoreId)) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    private function menuSlugExists(string $slug, ?int $ignoreId): bool
    {
        $query = Menu::query()->where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
