<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

use TheNguyen\CMS\Models\Media;

/**
 * Public, immutable view of one media item for frontend consumers (CORE-40-FRONTEND-MEDIA-R1).
 *
 * Stable contract: exactly these fields, in this order, are exposed by toArray() and the
 * /account/media JSON API. Storage details (disk, filesystem path, folder, owner id, size,
 * original filename, description) are deliberately absent.
 *
 * `url` is the stored public URL of the file. Media files live on a PUBLIC disk: anyone who
 * knows the URL can fetch the bytes. Owner scoping protects discovery and selection through
 * the API, not the confidentiality of a URL that has already been shared or published.
 */
final class MediaSummary
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $mimeType,
        public readonly ?string $title,
        public readonly ?string $alt,
        public readonly ?string $createdAt,
    ) {}

    public static function fromMedia(Media $media): self
    {
        return new self(
            id: (int) $media->getKey(),
            url: (string) $media->url,
            width: $media->width !== null ? (int) $media->width : null,
            height: $media->height !== null ? (int) $media->height : null,
            mimeType: $media->mime_type !== null ? (string) $media->mime_type : null,
            title: $media->title !== null && $media->title !== '' ? (string) $media->title : null,
            alt: $media->alt !== null && $media->alt !== '' ? (string) $media->alt : null,
            createdAt: $media->created_at?->toIso8601String(),
        );
    }

    /**
     * @return array{id: int, url: string, width: ?int, height: ?int, mime_type: ?string, title: ?string, alt: ?string, created_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
            'mime_type' => $this->mimeType,
            'title' => $this->title,
            'alt' => $this->alt,
            'created_at' => $this->createdAt,
        ];
    }
}
