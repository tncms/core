<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision;

use Illuminate\Support\Collection;
use TheNguyen\CMS\Revision\Contracts\RevisionableInterface;
use TheNguyen\CMS\Revision\Contracts\RevisionManagerInterface;
use TheNguyen\CMS\Revision\Contracts\RevisionRepositoryInterface;
use TheNguyen\CMS\Revision\DTOs\RevisionContext;
use TheNguyen\CMS\Revision\DTOs\RevisionRecord;
use TheNguyen\CMS\Revision\DTOs\RevisionSnapshot;
use TheNguyen\CMS\Revision\Models\Revision;
use TheNguyen\CMS\Revision\Support\RevisionEvents;

/**
 * The revision platform façade (`cms.revision`).
 *
 * Entity- and driver-agnostic: it snapshots a Revisionable's per-locale field
 * map, records only-on-change (checksum-gated), keeps one immutable history per
 * locale, and prunes to retention. Everything is gated by the `enabled` flag —
 * disabled, every method is inert and the system behaves exactly as today.
 */
final class RevisionManager implements RevisionManagerInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly RevisionRepositoryInterface $repository,
        private readonly array $config = [],
    ) {}

    public function createRevision(RevisionableInterface $entity, string $locale, ?RevisionContext $context = null): ?Revision
    {
        if (! $this->enabled()) {
            return null;
        }

        $type = $entity->revisionEntityType();
        $id = $entity->revisionEntityId();

        $snapshot = RevisionSnapshot::fromFields($entity->snapshotForLocale($locale));

        // An unauthored locale has nothing worth a revision.
        if ($snapshot->isEmpty()) {
            return null;
        }

        // No-op edit: the localized fields are byte-identical to the latest
        // revision, so recording another row would be noise.
        $latestChecksum = $this->repository->latestChecksum($type, $id, $locale);
        if ($latestChecksum !== null && hash_equals($latestChecksum, $snapshot->checksum)) {
            return null;
        }

        $context ??= RevisionContext::default();

        $this->fireAction(RevisionEvents::CREATING, $entity, $locale, $snapshot, $context);

        $number = $this->repository->latestNumber($type, $id, $locale) + 1;

        $revision = $this->repository->append(new RevisionRecord(
            $type,
            $id,
            $locale,
            $number,
            $snapshot,
            $context,
        ));

        $this->fireAction(RevisionEvents::CREATED, $revision, $entity, $context);

        return $revision;
    }

    public function latest(RevisionableInterface $entity, string $locale): ?Revision
    {
        return $this->repository->latest(
            $entity->revisionEntityType(),
            $entity->revisionEntityId(),
            $locale,
        );
    }

    public function history(RevisionableInterface $entity, string $locale): Collection
    {
        return $this->repository->forEntityLocale(
            $entity->revisionEntityType(),
            $entity->revisionEntityId(),
            $locale,
        );
    }

    public function exists(RevisionableInterface $entity, string $locale): bool
    {
        return $this->repository->exists(
            $entity->revisionEntityType(),
            $entity->revisionEntityId(),
            $locale,
        );
    }

    public function prune(RevisionableInterface $entity, string $locale): int
    {
        if (! $this->enabled() || $this->pruneStrategy() === 'none') {
            return 0;
        }

        $keep = $this->maxPerLocale();

        // 0 (or negative) means unlimited — retain everything.
        if ($keep <= 0) {
            return 0;
        }

        $this->fireAction(RevisionEvents::PRUNING, $entity, $locale, $keep);

        $deleted = $this->repository->pruneKeeping(
            $entity->revisionEntityType(),
            $entity->revisionEntityId(),
            $locale,
            $keep,
        );

        $this->fireAction(RevisionEvents::PRUNED, $entity, $locale, $deleted);

        return $deleted;
    }

    public function diagnostics(?RevisionableInterface $entity = null): array
    {
        $report = [
            'enabled' => $this->enabled(),
            'max_per_locale' => $this->maxPerLocale(),
            'prune_strategy' => $this->pruneStrategy(),
            'diagnostics' => $this->diagnosticsEnabled(),
        ];

        if ($entity === null || ! $this->diagnosticsEnabled()) {
            return $report;
        }

        $counts = $this->repository->countsByLocale(
            $entity->revisionEntityType(),
            $entity->revisionEntityId(),
        );

        $report['entity'] = [
            'type' => $entity->revisionEntityType(),
            'id' => $entity->revisionEntityId(),
            'locales' => $entity->revisionableLocales(),
            'counts' => $counts,
            'total' => array_sum($counts),
        ];

        return $report;
    }

    private function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    private function maxPerLocale(): int
    {
        return (int) ($this->config['max_per_locale'] ?? 5);
    }

    private function pruneStrategy(): string
    {
        return (string) ($this->config['prune_strategy'] ?? 'keep_latest');
    }

    private function diagnosticsEnabled(): bool
    {
        return (bool) ($this->config['diagnostics'] ?? true);
    }

    /**
     * Fire an action hook when the hook system is available. The platform must
     * work in isolation, so a missing hook manager is never fatal.
     */
    private function fireAction(string $hook, mixed ...$args): void
    {
        if (function_exists('do_action')) {
            do_action($hook, ...$args);
        }
    }
}
