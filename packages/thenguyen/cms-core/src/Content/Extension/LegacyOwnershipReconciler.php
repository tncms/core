<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Content\Extension;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use TheNguyen\CMS\ContentAuthority\ContentAuthorityAudit;

/**
 * Folds a development-only CONTENT-EXT `cms_content_ownership` table into
 * the single ownership authority `cms_contents.managed_by`
 * (CORE-UNIFIED-OWNERSHIP-R1).
 *
 * Never chooses a winner: any legacy row whose content is already managed by
 * a DIFFERENT plugin, whose content is missing, or whose values are invalid
 * is a conflict, and {@see plan()} reports it while {@see apply()} refuses to
 * run. Applying sets managed_by only where it is NULL, adds a policy row only
 * where none exists, audits each ownership it actually sets, and is therefore
 * idempotent. The legacy table is never read by Core afterwards and is
 * dropped only by an explicit operator step after {@see verify()} passes.
 */
final class LegacyOwnershipReconciler
{
    public const LEGACY_TABLE = 'cms_content_ownership';

    private const BATCH = 500;

    private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public function legacyTableExists(): bool
    {
        return Schema::hasTable(self::LEGACY_TABLE);
    }

    /**
     * Dry run: what would be mapped, and every conflict.
     *
     * @return array{legacy_rows: int, to_adopt: int, already_consistent: int, conflicts: list<array{content_id: int, legacy_owner: string, managed_by: string|null, reason: string}>}
     */
    public function plan(): array
    {
        $report = ['legacy_rows' => 0, 'to_adopt' => 0, 'already_consistent' => 0, 'conflicts' => []];

        if (! $this->legacyTableExists()) {
            return $report;
        }

        DB::table(self::LEGACY_TABLE.' as o')
            ->leftJoin('cms_contents as c', 'c.id', '=', 'o.content_id')
            ->select(['o.content_id', 'o.owner', 'o.fallback_policy', 'o.availability', 'c.id as cid', 'c.managed_by', 'c.type'])
            ->orderBy('o.content_id')
            ->chunk(self::BATCH, function ($rows) use (&$report): void {
                foreach ($rows as $row) {
                    $report['legacy_rows']++;
                    $reason = $this->conflictReason($row);

                    if ($reason !== null) {
                        $report['conflicts'][] = [
                            'content_id' => (int) $row->content_id,
                            'legacy_owner' => (string) $row->owner,
                            'managed_by' => $row->managed_by !== null ? (string) $row->managed_by : null,
                            'reason' => $reason,
                        ];
                    } elseif ($row->managed_by === null) {
                        $report['to_adopt']++;
                    } else {
                        $report['already_consistent']++;
                    }
                }
            });

        return $report;
    }

    /**
     * Apply the mapping in ONE transaction (locked batches inside it).
     * Refuses when any conflict exists; a conflict appearing mid-run rolls
     * everything back and throws.
     *
     * @return array{adopted: int, policies_created: int}
     */
    public function apply(): array
    {
        $plan = $this->plan();

        if ($plan['conflicts'] !== []) {
            throw new RuntimeException('Legacy content ownership has conflicts; nothing was changed.');
        }

        $result = ['adopted' => 0, 'policies_created' => 0];

        if (! $this->legacyTableExists()) {
            return $result;
        }

        $ids = DB::table(self::LEGACY_TABLE)->orderBy('content_id')->pluck('content_id')->map(static fn ($id): int => (int) $id)->all();

        DB::transaction(function () use ($ids, &$result): void {
            foreach (array_chunk($ids, self::BATCH) as $batch) {
                (function () use ($batch, &$result): void {
                    $contents = DB::table('cms_contents')->whereIn('id', $batch)->lockForUpdate()->get(['id', 'managed_by', 'revision'])->keyBy('id');
                    $legacy = DB::table(self::LEGACY_TABLE)->whereIn('content_id', $batch)->get()->keyBy('content_id');

                    foreach ($legacy as $id => $row) {
                        $content = $contents->get($id);

                        if ($content === null || ($content->managed_by !== null && $content->managed_by !== $row->owner)) {
                            throw new RuntimeException("Legacy content ownership conflict appeared for content {$id}; nothing was applied.");
                        }

                        if ($content->managed_by === null) {
                            DB::table('cms_contents')->where('id', $id)->whereNull('managed_by')
                                ->update(['managed_by' => $row->owner, 'revision' => DB::raw('revision + 1')]);
                            ContentAuthorityAudit::record((int) $id, 'reconciled', (string) $row->owner, null, (string) $row->owner, 'reconcile', null, (int) $content->revision + 1, 'reconcile:content-ext');
                            $result['adopted']++;
                        }

                        $result['policies_created'] += DB::table('cms_content_owner_policies')->insertOrIgnore([
                            'content_id' => (int) $id,
                            'fallback_policy' => (string) $row->fallback_policy,
                            'availability' => (string) $row->availability,
                            'retry_after' => $row->retry_after,
                            'operator_assigned' => (bool) ($row->operator_assigned ?? false),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                })();
            }
        });

        return $result;
    }

    /**
     * Legacy rows not yet reflected in the single authority (empty = safe to
     * drop the legacy table).
     *
     * @return list<int>
     */
    public function verify(): array
    {
        if (! $this->legacyTableExists()) {
            return [];
        }

        return DB::table(self::LEGACY_TABLE.' as o')
            ->leftJoin('cms_contents as c', 'c.id', '=', 'o.content_id')
            ->leftJoin('cms_content_owner_policies as p', 'p.content_id', '=', 'o.content_id')
            ->where(static fn ($q) => $q->whereNull('c.id')->orWhereNull('c.managed_by')->orWhereColumn('c.managed_by', '!=', 'o.owner')->orWhereNull('p.content_id'))
            ->orderBy('o.content_id')
            ->pluck('o.content_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** Drop the legacy table, only when every row is verified. */
    public function dropLegacy(): void
    {
        $unverified = $this->verify();

        if ($unverified !== []) {
            throw new RuntimeException(count($unverified).' legacy ownership rows are not reflected in managed_by; the legacy table was kept.');
        }

        Schema::dropIfExists(self::LEGACY_TABLE);
    }

    /** @param  array<string, mixed>  $report */
    public function writeReport(array $report): string
    {
        $path = storage_path('app/ownership-reconcile-report-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function conflictReason(object $row): ?string
    {
        if ($row->cid === null) {
            return 'content_missing';
        }

        if (preg_match(self::SLUG, (string) $row->owner) !== 1) {
            return 'invalid_owner';
        }

        if (ContentFallbackPolicy::tryFrom((string) $row->fallback_policy) === null || ContentAvailability::tryFrom((string) $row->availability) === null) {
            return 'invalid_policy';
        }

        if ($row->managed_by !== null && $row->managed_by !== $row->owner) {
            return 'managed_by_other';
        }

        if (! in_array((string) $row->type, ['post', 'page'], true)) {
            return 'unsupported_type';
        }

        if (FrontPage::is((int) $row->content_id, (string) $row->type)) {
            return 'front_page';
        }

        return null;
    }
}
