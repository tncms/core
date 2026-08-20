<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Database\Seeders;

use Illuminate\Database\Seeder;
use TheNguyen\CMS\Models\Content;
use TheNguyen\CMS\Models\Term;
use TheNguyen\CMS\Services\ContentManager;
use TheNguyen\CMS\Services\TaxonomyManager;

class CmsContentSeeder extends Seeder
{
    public function run(): void
    {
        /** @var TaxonomyManager $taxonomies */
        $taxonomies = app('cms.taxonomy');
        $taxonomies->ensureCoreTaxonomies();

        /** @var ContentManager $contents */
        $contents = app('cms.content');

        $category = $this->ensureTerm($taxonomies, 'category', [
            'name' => 'Tin tức',
            'slug' => 'tin-tuc',
        ]);

        $tag = $this->ensureTerm($taxonomies, 'tag', [
            'name' => 'Laravel',
            'slug' => 'laravel',
        ]);

        $hasPages = Content::query()->where('type', 'page')->exists();
        if (! $hasPages) {
            $contents->create([
                'type' => 'page',
                'status' => 'published',
                'comment_status' => 'closed',
                'locale' => 'vi',
                'title' => 'Trang chủ',
                'slug' => 'trang-chu',
                'content' => 'Welcome to TN CMS',
            ]);
        }

        $hasPosts = Content::query()->where('type', 'post')->exists();
        if (! $hasPosts) {
            $samplePost = $contents->create([
                'type' => 'post',
                'status' => 'published',
                'comment_status' => 'open',
                'locale' => 'vi',
                'title' => 'Bài viết đầu tiên',
                'slug' => 'bai-viet-dau-tien',
                'excerpt' => 'Bài viết mẫu đầu tiên của TN CMS.',
                'content' => 'Nội dung bài viết mẫu.',
                'term_ids' => array_filter([
                    $category?->id,
                    $tag?->id,
                ]),
            ]);

            if ($samplePost && ($category || $tag)) {
                $samplePost->terms()->sync(array_filter([
                    $category?->id,
                    $tag?->id,
                ]));
            }
        }
    }

    /**
     * @param  array{name: string, slug: string}  $data
     */
    private function ensureTerm(TaxonomyManager $taxonomies, string $taxonomySlug, array $data): ?Term
    {
        $existing = $taxonomies->findTermBySlug($data['slug'], 'vi', $taxonomySlug);

        if ($existing) {
            return $existing;
        }

        return $taxonomies->createTerm($taxonomySlug, [
            'locale' => 'vi',
            'name' => $data['name'],
            'slug' => $data['slug'],
        ]);
    }
}
