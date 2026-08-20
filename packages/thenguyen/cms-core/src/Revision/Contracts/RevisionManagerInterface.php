<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Contracts;

use Illuminate\Support\Collection;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;
use TheNguyen\CMS\Revision\Models\Revision;

/**
 * The revision platform façade (`cms.revision`). Entity- and driver-agnostic:
 * it consumes a Revisionable's per-locale snapshot and records/prunes an
 * immutable, locale-scoped history. Everything is gated by
 * config('revisions.enabled') — disabled, every method is inert.
 *
 * @since 1.0
 *
 * @stable
 */
interface RevisionManagerInterface
{
    /**
     * Record a revision for one locale if the platform is enabled, the snapshot
     * is non-empty, and it differs from the latest (checksum gate). Returns the
     * new revision, or null when nothing was recorded. Meant to be called INSIDE
     * the owning write's transaction so a rollback removes it atomically.
     */
    public function createRevision(RevisionableInterface $entity, string $locale, ?RevisionContext $context = null): ?Revision;

    /** The newest revision for the locale, or null. */
    public function latest(RevisionableInterface $entity, string $locale): ?Revision;

    /**
     * The locale's history, newest-first.
     *
     * @return Collection<int, Revision>
     */
    public function history(RevisionableInterface $entity, string $locale): Collection;

    /** Whether any revision exists for the locale. */
    public function exists(RevisionableInterface $entity, string $locale): bool;

    /**
     * Enforce retention for the locale (keep newest max_per_locale). Meant to run
     * AFTER a successful commit. Returns the number of rows pruned.
     */
    public function prune(RevisionableInterface $entity, string $locale): int;

    /**
     * Platform state, plus per-locale counts for $entity when one is given and
     * diagnostics are enabled.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(?RevisionableInterface $entity = null): array;
}
