<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

use Illuminate\Support\Facades\DB;

/**
 * The plugin's own write path. Every write ends with
 * app('cms.sitemap')->invalidate(), which bumps only this plugin's manifest.
 */
final class Catalog
{
    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes): Item
    {
        $item = Item::query()->create($attributes + ['status' => 'published']);
        app('cms.sitemap')->invalidate();

        return $item;
    }

    /** @param array<string, mixed> $attributes */
    public static function update(int $id, array $attributes): void
    {
        Item::withTrashed()->whereKey($id)->update($attributes + ['updated_at' => now()]);
        app('cms.sitemap')->invalidate();
    }

    public static function delete(int $id): void
    {
        Item::query()->whereKey($id)->first()?->delete();
        app('cms.sitemap')->invalidate();
    }

    /** Bulk products for chunking probes (bounded inserts of 500). */
    public static function seedProducts(int $count, int $englishEvery = 2): void
    {
        $now = now();
        $start = (int) Item::withTrashed()->where('family', 'ec_product')->count();

        foreach (array_chunk(range($start + 1, $start + $count), 500) as $batch) {
            DB::table('smc_items')->insert(array_map(static fn (int $i): array => [
                'family' => 'ec_product', 'slug_vi' => 'san-pham-'.$i,
                'slug_en' => $i % $englishEvery === 0 ? 'product-'.$i : null,
                'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
            ], $batch));
        }

        app('cms.sitemap')->invalidate();
    }

    /** One representative item per family plus non-public ones (draft, private, soft-deleted). */
    public static function seedFamilies(): array
    {
        $ids = [];
        $ids['article'] = self::create(['family' => 'kb_article', 'slug_vi' => 'kien-thuc-co-ban', 'slug_en' => 'basic-knowledge'])->id;
        $ids['article_vi_only'] = self::create(['family' => 'kb_article', 'slug_vi' => 'chi-tieng-viet'])->id;
        $ids['article_draft'] = self::create(['family' => 'kb_article', 'slug_vi' => 'ban-nhap-bi-mat', 'slug_en' => 'secret-draft', 'status' => 'draft'])->id;
        $ids['article_private'] = self::create(['family' => 'kb_article', 'slug_vi' => 'rieng-tu-bi-mat', 'status' => 'private'])->id;
        $deleted = self::create(['family' => 'kb_article', 'slug_vi' => 'da-xoa', 'slug_en' => 'deleted']);
        self::delete($deleted->id);
        $ids['article_deleted'] = $deleted->id;
        $ids['topic'] = self::create(['family' => 'kb_category', 'slug_vi' => 'lich-su', 'slug_en' => 'history'])->id;
        $ids['draw_north'] = self::create(['family' => 'lottery_draw', 'slug_vi' => 'mb', 'slug_en' => 'mb', 'region' => 'mien-bac', 'draw_date' => '2026-11-01'])->id;
        $ids['draw_south'] = self::create(['family' => 'lottery_draw', 'slug_vi' => 'mn', 'region' => 'mien-nam', 'draw_date' => '2026-11-02'])->id;
        $ids['product'] = self::create(['family' => 'ec_product', 'slug_vi' => 'ao-thun', 'slug_en' => 't-shirt'])->id;
        $ids['product_category'] = self::create(['family' => 'ec_category', 'slug_vi' => 'thoi-trang', 'slug_en' => 'fashion'])->id;

        return $ids;
    }
}
