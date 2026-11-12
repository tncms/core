<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media\Frontend;

/**
 * Listing parameters for {@see FrontendMediaLibrary::listOwn()}.
 *
 * Deliberately has no owner, type or "all" option: the owner is always the actor passed to
 * the service, and R1 lists images only. perPage is clamped to 1..48. The cursor is the
 * opaque `next_cursor` of a previous page; it is validated by the service.
 */
final class FrontendMediaQuery
{
    public const MAX_PER_PAGE = 48;

    public const DEFAULT_PER_PAGE = 24;

    public const MAX_SEARCH_LENGTH = 100;

    public readonly string $search;

    public readonly int $perPage;

    public function __construct(
        ?string $search = null,
        public readonly ?string $cursor = null,
        int $perPage = self::DEFAULT_PER_PAGE,
    ) {
        $this->search = trim((string) $search);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
