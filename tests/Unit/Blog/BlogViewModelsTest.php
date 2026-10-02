<?php

declare(strict_types=1);

namespace Tests\Unit\Blog;

use PHPUnit\Framework\TestCase;
use TheNguyen\CMS\View\AdjacentPostViewModel;
use TheNguyen\CMS\View\BreadcrumbItemViewModel;
use TheNguyen\CMS\View\MediaViewModel;
use TheNguyen\CMS\View\PostCardViewModel;
use TheNguyen\CMS\View\PublicAuthorViewModel;
use TheNguyen\CMS\View\TaxonomyLinkViewModel;

/**
 * CORE-BLOG-1 — pure contract tests for the post-detail presentation ViewModels.
 * No database: these assert the immutable public shape and that serialization
 * can never leak private state.
 */
final class BlogViewModelsTest extends TestCase
{
    public function test_breadcrumb_item_shape(): void
    {
        $item = new BreadcrumbItemViewModel('Home', '/', false);
        $current = new BreadcrumbItemViewModel('Post', null, true);

        $this->assertSame(['label' => 'Home', 'url' => '/', 'is_current' => false], $item->toArray());
        $this->assertSame(['label' => 'Post', 'url' => null, 'is_current' => true], $current->toArray());
    }

    public function test_taxonomy_link_shape_and_optional_count(): void
    {
        $link = new TaxonomyLinkViewModel('category', 'News', '/category/news');

        $this->assertNull($link->count);
        $this->assertSame(
            ['type' => 'category', 'label' => 'News', 'url' => '/category/news', 'count' => null],
            $link->toArray(),
        );
    }

    public function test_adjacent_post_formats_timestamp(): void
    {
        $vm = new AdjacentPostViewModel('Older', '/blog/older', new \DateTimeImmutable('2026-01-02T03:04:05+00:00'));

        $array = $vm->toArray();
        $this->assertSame('Older', $array['title']);
        $this->assertSame('/blog/older', $array['url']);
        $this->assertSame('2026-01-02T03:04:05+00:00', $array['published_at']);
        $this->assertNull((new AdjacentPostViewModel('x', '/x'))->toArray()['published_at']);
    }

    public function test_public_author_exposes_only_safe_fields(): void
    {
        $vm = new PublicAuthorViewModel(
            name: 'Jane',
            avatar: MediaViewModel::fromUrl('/avatar.png', 'Jane'),
            bio: 'Writer',
            url: null,
        );

        $array = $vm->toArray();

        // Exactly the public keys — no email/phone/username/roles can appear here.
        $this->assertSame(['name', 'avatar', 'bio', 'url'], array_keys($array));
        $this->assertSame('Jane', $array['name']);
        $this->assertSame('Writer', $array['bio']);
        $this->assertNull($array['url']);
        $this->assertSame('/avatar.png', $array['avatar']['url']);

        $flat = json_encode($array);
        $this->assertStringNotContainsStringIgnoringCase('email', (string) $flat);
        $this->assertStringNotContainsStringIgnoringCase('phone', (string) $flat);
    }

    public function test_post_card_shape(): void
    {
        $card = new PostCardViewModel(
            id: 7,
            title: 'Hello',
            url: '/blog/hello',
            excerpt: 'Hi',
            publishedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            featuredMedia: MediaViewModel::fromUrl('/f.png', 'Hello'),
            primaryCategory: new TaxonomyLinkViewModel('category', 'News', '/category/news'),
        );

        $array = $card->toArray();
        $this->assertSame(7, $array['id']);
        $this->assertSame('/blog/hello', $array['url']);
        $this->assertSame('/f.png', $array['featured_media']['url']);
        $this->assertSame('News', $array['primary_category']['label']);
    }
}
