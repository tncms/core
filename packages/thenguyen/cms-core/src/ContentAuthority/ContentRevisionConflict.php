<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * The expected revision no longer matches the stored one: somebody else changed
 * the content (row, a translation or its terms) after the caller read it.
 * Nothing was written; reload and retry.
 */
final class ContentRevisionConflict extends ContentWriteException
{
    public function __construct(
        int $contentId,
        private readonly int $expected,
        private readonly int $actual,
    ) {
        parent::__construct(
            'revision_conflict',
            'This content was changed by someone else after you opened it. Reload it and apply your changes again.',
            $contentId,
        );
    }

    public function expected(): int
    {
        return $this->expected;
    }

    public function actual(): int
    {
        return $this->actual;
    }
}
