<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\DTOs;

use TheNguyen\CMS\Revision\Support\RevisionChecksum;

/**
 * An immutable localized field map plus its canonical sha256 checksum. Built
 * from what a Revisionable exposes for one locale; the checksum drives no-op
 * detection so an unchanged save records nothing.
 */
final class RevisionSnapshot
{
    /**
     * @param  array<string, mixed>  $fields
     */
    private function __construct(
        public readonly array $fields,
        public readonly string $checksum,
    ) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function fromFields(array $fields): self
    {
        return new self($fields, RevisionChecksum::forFields($fields));
    }

    /**
     * A snapshot is empty when it carries no meaningful content for the locale —
     * an unauthored locale. Such snapshots are not worth a revision.
     */
    public function isEmpty(): bool
    {
        foreach ($this->fields as $value) {
            if (is_array($value)) {
                if ($value !== []) {
                    return false;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }
}
