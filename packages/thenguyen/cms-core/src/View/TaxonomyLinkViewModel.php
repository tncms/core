<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

/**
 * A public taxonomy-term link (CORE-BLOG-1). Immutable presentation contract for
 * category/tag navigation: localized label, canonical locale-aware URL, and an
 * optional published-post count that is ONLY populated when a trustworthy count
 * is available (the dormant cms_terms.count column is never surfaced here).
 *
 * Themes consume this ViewModel, never a raw Term model.
 */
final class TaxonomyLinkViewModel
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $url,
        public readonly ?int $count = null,
    ) {}

    /**
     * @return array{type: string, label: string, url: string, count: ?int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'url' => $this->url,
            'count' => $this->count,
        ];
    }
}
