<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Contracts;

use Illuminate\Support\Collection;
use TheNguyen\CMS\Revision\DTOs\RevisionRecord;
use TheNguyen\CMS\Revision\Models\Revision;

/**
 * Storage seam over the append-only history. The default implementation is
 * relational (cms_revisions); the seam allows an alternate backend without
 * touching the manager. Every operation is scoped by (entity_type, entity_id,
 * locale) — one independent history per locale.
 */
interface RevisionRepositoryInterface
{
    /** Append one immutable row and return it. */
    public function append(RevisionRecord $record): Revision;

    /** The newest revision for the locale, or null when none exists. */
    public function latest(string $type, int|string $id, string $locale): ?Revision;

    /**
     * The full history for the locale, newest-first.
     *
     * @return Collection<int, Revision>
     */
    public function forEntityLocale(string $type, int|string $id, string $locale): Collection;

    /** The highest revision_number for the locale, or 0 when none. */
    public function latestNumber(string $type, int|string $id, string $locale): int;

    /** The newest revision's checksum, or null when none — for no-op detection. */
    public function latestChecksum(string $type, int|string $id, string $locale): ?string;

    /** Whether any revision exists for the locale. */
    public function exists(string $type, int|string $id, string $locale): bool;

    /**
     * Delete all but the newest $keep revisions for the locale via a bulk
     * query-builder delete (bypasses the immutability model guard). Returns the
     * number of rows removed. $keep <= 0 means unlimited (deletes nothing).
     */
    public function pruneKeeping(string $type, int|string $id, string $locale, int $keep): int;

    /**
     * Revision counts keyed by locale for one entity (diagnostics).
     *
     * @return array<string, int>
     */
    public function countsByLocale(string $type, int|string $id): array;
}
