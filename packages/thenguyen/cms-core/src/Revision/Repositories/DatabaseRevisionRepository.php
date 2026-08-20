<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Revision\Contracts\RevisionRepositoryInterface;
use TheNguyen\CMS\Revision\DTOs\RevisionRecord;
use TheNguyen\CMS\Revision\Models\Revision;

/**
 * Relational implementation of the revision store over `cms_revisions`.
 *
 * Reads go through the immutable {@see Revision} model; retention pruning uses a
 * raw query-builder bulk delete so it bypasses the model's append-only guard
 * while Eloquent-level immutability still holds for every other path.
 */
final class DatabaseRevisionRepository implements RevisionRepositoryInterface
{
    private const TABLE = 'cms_revisions';

    public function append(RevisionRecord $record): Revision
    {
        $revision = new Revision;

        $revision->forceFill([
            'entity_type' => $record->entityType,
            'entity_id' => (string) $record->entityId,
            'locale' => $record->locale,
            'revision_number' => $record->revisionNumber,
            'snapshot' => $record->snapshot->fields,
            'checksum' => $record->snapshot->checksum,
            'author_id' => $record->context->authorId,
            'source' => $record->context->source->value,
            'reason' => $record->context->reason,
            'created_at' => now(),
        ]);

        $revision->save();

        return $revision;
    }

    public function latest(string $type, int|string $id, string $locale): ?Revision
    {
        return $this->scoped($type, $id, $locale)
            ->orderByDesc('revision_number')
            ->first();
    }

    public function forEntityLocale(string $type, int|string $id, string $locale): Collection
    {
        return $this->scoped($type, $id, $locale)
            ->orderByDesc('revision_number')
            ->get();
    }

    public function latestNumber(string $type, int|string $id, string $locale): int
    {
        return (int) $this->scoped($type, $id, $locale)->max('revision_number');
    }

    public function latestChecksum(string $type, int|string $id, string $locale): ?string
    {
        $checksum = $this->scoped($type, $id, $locale)
            ->orderByDesc('revision_number')
            ->value('checksum');

        return $checksum !== null ? (string) $checksum : null;
    }

    public function exists(string $type, int|string $id, string $locale): bool
    {
        return $this->scoped($type, $id, $locale)->exists();
    }

    public function pruneKeeping(string $type, int|string $id, string $locale, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        // The revision_number of the oldest revision we intend to keep. Anything
        // strictly older than it is pruned. Done via the query builder so the
        // immutability model guard is bypassed for this sanctioned removal.
        $keepNumbers = DB::table(self::TABLE)
            ->where('entity_type', $type)
            ->where('entity_id', (string) $id)
            ->where('locale', $locale)
            ->orderByDesc('revision_number')
            ->limit($keep)
            ->pluck('revision_number');

        if ($keepNumbers->count() < $keep) {
            return 0;
        }

        $threshold = (int) $keepNumbers->min();

        return DB::table(self::TABLE)
            ->where('entity_type', $type)
            ->where('entity_id', (string) $id)
            ->where('locale', $locale)
            ->where('revision_number', '<', $threshold)
            ->delete();
    }

    public function countsByLocale(string $type, int|string $id): array
    {
        return DB::table(self::TABLE)
            ->where('entity_type', $type)
            ->where('entity_id', (string) $id)
            ->selectRaw('locale, COUNT(*) as aggregate')
            ->groupBy('locale')
            ->pluck('aggregate', 'locale')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Revision>
     */
    private function scoped(string $type, int|string $id, string $locale)
    {
        return Revision::query()
            ->where('entity_type', $type)
            ->where('entity_id', (string) $id)
            ->where('locale', $locale);
    }
}
