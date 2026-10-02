<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

use DateTimeInterface;
use TheNguyen\CMS\Models\Content;

/**
 * Shared, bounded post-card projection (CORE-BLOG-1) used by related posts,
 * recent posts, and any card-style list. Immutable and model-free: the source
 * Content is consumed at construction and never stored, so no raw model or lazy
 * relation access can reach a theme through this object.
 *
 * {@see fromContent()} reads ONLY in-memory data (translations/terms must be
 * eager-loaded by the caller) and returns null when the post has no resolvable
 * canonical URL in the requested locale, so unlinkable cards are skipped.
 */
final class PostCardViewModel
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $url,
        public readonly ?string $excerpt = null,
        public readonly ?DateTimeInterface $publishedAt = null,
        public readonly ?MediaViewModel $featuredMedia = null,
        public readonly ?TaxonomyLinkViewModel $primaryCategory = null,
    ) {}

    /**
     * Build from a Content post using only already-loaded relations. Returns null
     * when no canonical locale URL resolves (content_url() '#' sentinel).
     */
    public static function fromContent(
        Content $post,
        string $locale,
        ?TaxonomyLinkViewModel $primaryCategory = null,
    ): ?self {
        $url = content_url($post, $locale);

        if ($url === '#') {
            return null;
        }

        $featured = ($post->featured_image !== null && $post->featured_image !== '')
            ? MediaViewModel::fromUrl($post->featured_image, $post->translatedTitle($locale))
            : null;

        return new self(
            id: (int) $post->getKey(),
            title: $post->translatedTitle($locale),
            url: $url,
            excerpt: self::excerptFor($post, $locale),
            publishedAt: $post->published_at,
            featuredMedia: $featured,
            primaryCategory: $primaryCategory,
        );
    }

    /**
     * Current-locale excerpt read from already-loaded translations (no query);
     * null when unloaded or absent so the card never triggers a lazy load.
     */
    private static function excerptFor(Content $post, string $locale): ?string
    {
        if (! $post->relationLoaded('translations')) {
            return null;
        }

        $excerpt = $post->translations->firstWhere('locale', $locale)?->excerpt;

        return is_string($excerpt) && $excerpt !== '' ? $excerpt : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
            'featured_media' => $this->featuredMedia?->toArray(),
            'primary_category' => $this->primaryCategory?->toArray(),
        ];
    }
}
