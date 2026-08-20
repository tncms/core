<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\DTOs;

use TheNguyen\CMS\Revision\Enums\RevisionSource;

/**
 * Immutable write metadata attached to a recorded revision: who (author),
 * how (source), and why (reason). All optional — a system/import write has no
 * author and needs no reason.
 */
final class RevisionContext
{
    public function __construct(
        public readonly ?int $authorId = null,
        public readonly RevisionSource $source = RevisionSource::System,
        public readonly ?string $reason = null,
    ) {}

    /**
     * The neutral default used when a caller records without supplying context.
     */
    public static function default(): self
    {
        return new self;
    }

    public static function admin(?int $authorId = null, ?string $reason = null): self
    {
        return new self($authorId, RevisionSource::Admin, $reason);
    }
}
