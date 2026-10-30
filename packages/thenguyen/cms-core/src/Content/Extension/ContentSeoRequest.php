<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

/** What a {@see ContentSeoProvider} is asked about. No model, no request. */
final class ContentSeoRequest
{
    public function __construct(
        public readonly int $contentId,
        public readonly string $contentType,
        public readonly string $locale,
    ) {}
}
