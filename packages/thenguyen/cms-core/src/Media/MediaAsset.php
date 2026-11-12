<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

use TheNguyen\CMS\Models\Media;

/**
 * Safe, immutable description of one Core media asset for plugin consumers (CORE-P12-1).
 *
 * Never carries the disk, folder, filesystem path, original client filename or uploader id.
 * `url` is the root-relative delivery URL Core already serves; build an absolute one with
 * {@see MediaAssetDirectory::absoluteUrl()} (configured APP_URL origin only, never the Host header).
 * `visibility` is always `public` today: every cms_media file lives on the public disk, so anyone
 * who knows the URL can fetch it. Private plugin files (e.g. Contact Form attachments) are not
 * Core media and are never returned.
 */
final class MediaAsset
{
    public const VISIBILITY_PUBLIC = 'public';

    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?string $mimeType,
        public readonly ?string $extension,
        public readonly int $size,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?string $alt,
        public readonly ?string $title,
        public readonly string $visibility,
        public readonly ?string $createdAt,
    ) {}

    public static function fromMedia(Media $media): self
    {
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? $v : null;

        return new self(
            id: (int) $media->getKey(),
            url: (string) $media->url,
            mimeType: $text($media->mime_type),
            extension: $text($media->extension),
            size: max(0, (int) $media->size),
            width: $media->width !== null ? (int) $media->width : null,
            height: $media->height !== null ? (int) $media->height : null,
            alt: $text($media->alt),
            title: $text($media->title),
            visibility: self::VISIBILITY_PUBLIC,
            createdAt: $media->created_at?->toIso8601String(),
        );
    }

    public function isImage(): bool
    {
        return $this->mimeType !== null && str_starts_with($this->mimeType, 'image/');
    }

    /** @return array{id:int,url:string,mime_type:?string,extension:?string,size:int,width:?int,height:?int,alt:?string,title:?string,visibility:string,created_at:?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'mime_type' => $this->mimeType,
            'extension' => $this->extension,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'title' => $this->title,
            'visibility' => $this->visibility,
            'created_at' => $this->createdAt,
        ];
    }
}
