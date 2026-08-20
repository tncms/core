<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

use TheNguyen\CMS\Models\Media;

/**
 * The single presentation contract for any rendered image (theme-architecture
 * `17` §5). Wraps a cms_media row when available and degrades gracefully from a
 * bare URL string. `srcset`/`sources`/`sizes` stay null until the media layer
 * produces variants, so the rendering partial can gain responsive images later
 * with no template change (forward-compatible by design).
 *
 * Immutable value object: themes consume MediaViewModel, never the Media model
 * directly (theme-architecture `20` Rules 1–3, 10).
 */
final class MediaViewModel
{
    /**
     * @param  array<int, array{type: string, srcset: string}>  $sources
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $url,
        public readonly string $alt = '',
        public readonly ?string $title = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $srcset = null,
        public readonly array $sources = [],
        public readonly ?string $sizes = null,
    ) {}

    /**
     * Build from a cms_media row. Alt/title use the model's SEO fallbacks
     * (alt → title → humanized filename) so the alt is never empty.
     */
    public static function fromMedia(Media $media): self
    {
        return new self(
            id: $media->id !== null ? (int) $media->id : null,
            url: (string) $media->url,
            alt: $media->seoAlt(),
            title: $media->seoTitle(),
            width: $media->width !== null ? (int) $media->width : null,
            height: $media->height !== null ? (int) $media->height : null,
        );
    }

    /**
     * Build from a bare URL string (today's featured_image shape). No id, no
     * dimensions, no srcset — the partial degrades to a plain <img>.
     */
    public static function fromUrl(string $url, string $altFallback = ''): self
    {
        return new self(
            id: null,
            url: $url,
            alt: $altFallback,
        );
    }

    /**
     * Stable, serializable shape (used by the JSON renderer + AI Builder).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'alt' => $this->alt,
            'title' => $this->title,
            'width' => $this->width,
            'height' => $this->height,
            'srcset' => $this->srcset,
            'sources' => $this->sources,
            'sizes' => $this->sizes,
        ];
    }
}
