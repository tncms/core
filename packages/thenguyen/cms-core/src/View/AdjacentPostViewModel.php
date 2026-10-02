<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

use DateTimeInterface;

/**
 * Previous/next post navigation item (CORE-BLOG-1). Immutable presentation
 * contract: localized title, canonical locale-aware URL, and an optional
 * publication timestamp suitable for a <time datetime> attribute. No raw model.
 */
final class AdjacentPostViewModel
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly ?DateTimeInterface $publishedAt = null,
    ) {}

    /**
     * @return array{title: string, url: string, published_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
        ];
    }
}
