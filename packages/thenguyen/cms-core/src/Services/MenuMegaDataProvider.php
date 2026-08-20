<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\View\MediaViewModel;

/**
 * Resolves the post payload for a dynamic mega-menu source (Phase 11D).
 *
 * A menu item with display=mega and a post-driven `mega_source` (latest_posts,
 * categories, tags, manual_posts) gets a ready-to-render list of cards attached
 * to its tree node here, in core — so the theme never queries from Blade. The
 * heavy lifting (published-visibility guard, taxonomy filters, ordering, locale
 * fallback, no N+1, no draft leakage) is delegated to {@see SectionDataProvider}
 * so there is a single Editorial Query Builder for the whole CMS.
 */
class MenuMegaDataProvider
{
    public function __construct(private readonly SectionDataProvider $sections) {}

    /**
     * Build the mega payload for a normalized menu-item meta array.
     *
     * @param  array<string, mixed>  $meta  Output of {@see \TheNguyen\CMS\Models\MenuItem::resolvedMeta()}.
     * @return array{items: array<int, array{title: string, url: string, excerpt: string, date: string, category: string, image: ?MediaViewModel}>}
     */
    public function resolve(array $meta, ?string $locale = null): array
    {
        $locale ??= current_locale();

        $settings = $this->settingsFor($meta);

        if ($settings === null) {
            return ['items' => []];
        }

        $items = array_map(
            fn (array $item): array => $this->presentItem($item),
            $this->sections->resolvePosts($settings, $locale),
        );

        return ['items' => $items];
    }

    /**
     * Map the dynamic mega meta onto the Editorial Query Builder settings
     * contract, or null when the source cannot yield anything (empty required
     * selection, or a non-dynamic source that should never reach this provider).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function settingsFor(array $meta): ?array
    {
        $source = is_string($meta['mega_source'] ?? null) ? $meta['mega_source'] : 'children';
        $limit = (int) ($meta['mega_limit'] ?? 6);
        $orderBy = is_string($meta['mega_order_by'] ?? null) ? $meta['mega_order_by'] : 'latest';
        $categoryIds = is_array($meta['mega_category_ids'] ?? null) ? $meta['mega_category_ids'] : [];
        $tagIds = is_array($meta['mega_tag_ids'] ?? null) ? $meta['mega_tag_ids'] : [];
        $postIds = is_array($meta['mega_post_ids'] ?? null) ? $meta['mega_post_ids'] : [];

        return match ($source) {
            'latest_posts' => [
                'source_type' => 'latest',
                'limit' => $limit,
                'order_by' => $orderBy,
            ],
            'categories' => $categoryIds === [] ? null : [
                'source_type' => 'categories',
                'category_ids' => $categoryIds,
                'limit' => $limit,
                'order_by' => $orderBy,
            ],
            'tags' => $tagIds === [] ? null : [
                'source_type' => 'tags',
                'tag_ids' => $tagIds,
                'limit' => $limit,
                'order_by' => $orderBy,
            ],
            // Manual keeps the author-picked order; limit follows the selection
            // size so every chosen post appears (SectionDataProvider clamps ≤24).
            'manual_posts' => $postIds === [] ? null : [
                'source_type' => 'manual',
                'manual_post_ids' => $postIds,
                'limit' => count($postIds),
            ],
            default => null,
        };
    }

    /**
     * Convert a SectionDataProvider raw item into the mega card shape: the bare
     * featured-image string becomes a MediaViewModel (or null) so the theme can
     * render media without any further resolution.
     *
     * @param  array<string, mixed>  $item
     * @return array{title: string, url: string, excerpt: string, date: string, category: string, image: ?MediaViewModel}
     */
    private function presentItem(array $item): array
    {
        $image = $item['image'] ?? null;

        return [
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'excerpt' => (string) ($item['excerpt'] ?? ''),
            'date' => (string) ($item['date'] ?? ''),
            'category' => (string) ($item['category'] ?? ''),
            'image' => (is_string($image) && $image !== '') ? MediaViewModel::fromUrl($image) : null,
        ];
    }
}
