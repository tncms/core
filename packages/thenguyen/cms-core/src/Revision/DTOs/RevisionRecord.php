<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\DTOs;

/**
 * The fully-resolved payload a repository appends as one immutable row: entity
 * identity, the pinned locale, the assigned monotonic revision number, the
 * snapshot, and the write context.
 */
final class RevisionRecord
{
    public function __construct(
        public readonly string $entityType,
        public readonly int|string $entityId,
        public readonly string $locale,
        public readonly int $revisionNumber,
        public readonly RevisionSnapshot $snapshot,
        public readonly RevisionContext $context,
    ) {}
}
