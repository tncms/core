<?php

declare(strict_types=1);

namespace TheNguyen\CMS\View;

/**
 * One breadcrumb trail item (CORE-BLOG-1). Immutable presentation contract:
 * themes render the label as plain escaped text and link only when {@see $url}
 * is non-null. No raw model, no HTML.
 */
final class BreadcrumbItemViewModel
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly bool $isCurrent = false,
    ) {}

    /**
     * @return array{label: string, url: ?string, is_current: bool}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
            'is_current' => $this->isCurrent,
        ];
    }
}
