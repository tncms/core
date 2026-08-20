<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision;

use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Revision\Contracts\RevisionableInterface;
use TheNguyen\CMS\Revision\Contracts\RevisionManagerInterface;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;
use TheNguyen\CMS\Revision\Models\Revision;

/**
 * The transaction-aware seam a future write path (ContentManager / TaxonomyManager)
 * calls to record and prune — packaged so the two lifecycle rules are explicit
 * and reusable. NOT wired into any write path in Phase 9.0A.
 *
 * Two calls, at two distinct transaction boundaries:
 *
 *   - {@see record()} is called INSIDE the owning write's DB::transaction, so a
 *     rolled-back write removes the revision atomically (zero orphans).
 *   - {@see pruneAfterCommit()} defers retention pruning until AFTER the write
 *     commits (via the connection's afterCommit callback), so a rolled-back
 *     write never prunes and pruning can't race the commit. Outside a
 *     transaction the callback runs immediately.
 */
final class RevisionRecorder
{
    public function __construct(
        private readonly RevisionManagerInterface $manager,
    ) {}

    /**
     * Record a revision for the locale the write touched. Returns the new
     * revision, or null when nothing was recorded (disabled / empty / no-op).
     * Call inside the owning write transaction.
     */
    public function record(RevisionableInterface $entity, string $locale, ?RevisionContext $context = null): ?Revision
    {
        return $this->manager->createRevision($entity, $locale, $context);
    }

    /**
     * Schedule locale-aware retention pruning to run after the current write
     * commits (immediately if there is no active transaction).
     */
    public function pruneAfterCommit(RevisionableInterface $entity, string $locale): void
    {
        DB::connection()->afterCommit(function () use ($entity, $locale): void {
            $this->manager->prune($entity, $locale);
        });
    }
}
