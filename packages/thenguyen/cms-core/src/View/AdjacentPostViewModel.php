<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

use DateTimeInterface;
use TheNguyen\CMS\Content\Extension\ContentOwnershipMarker;

/**
 * Previous/next post navigation item (CORE-BLOG-1). Immutable presentation
 * contract: localized title, canonical locale-aware URL, and an optional
 * publication timestamp suitable for a <time datetime> attribute. No raw model.
 * The content id (like PostCardViewModel's) lets a theme identify an
 * owner-managed neighbour from public data (CORE-38-TOPLIST-SEAMS-R1).
 */
final class AdjacentPostViewModel
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?DateTimeInterface $publishedAt = null,
        public readonly ?ContentOwnershipMarker $ownership = null,
        public readonly ?int $id = null,
    ) {}

    /**
     * @return array{title: string, url: string, published_at: ?string, ownership?: array{owner: string, state: string}, id?: int}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
        ] + ($this->ownership !== null ? ['ownership' => $this->ownership->toArray()] : [])
            + ($this->id !== null ? ['id' => $this->id] : []);
    }
}
