<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Core apply engine — replace-not-merge + stale Core removal (CORE-UPGRADE-1,
 * §24–§36), executed as a journaled per-FILE transaction (CORE-UPGRADE-WIN-R1).
 *
 * The ONE apply authority for callers that promote a verified staging tree in
 * a single step. It consumes the ownership model + the verified staging tree +
 * the site's OLD ownership manifest + the package's NEW ownership manifest and
 * delegates to {@see UpgradeTransaction}:
 *
 *     plan (validate, no mutation) → preserve live files → promote staged files
 *     → remove obsolete Core files (old − new) → finalize
 *
 * Until 7.1.35 whole Core roots were renamed aside and replaced. NTFS refuses to
 * rename a directory while any process holds a handle beneath it, and the old
 * fallback then cleared the live root in place ("failed to promote staged dir:
 * app"). Files are now moved one by one, so a root that is open elsewhere, or
 * that also holds operator files, is handled safely; any failure is rolled back
 * and surfaced with a verified {@see UpgradeOutcome}.
 *
 * Hard invariant (§25): every planned path is proven Core-owned, outside every
 * preserve prefix/sibling, free of traversal / ADS / device names, and not
 * reached through a symlink or junction, before anything is written.
 *
 * No plugin-specific hardcoding, no second path list — paths come from the
 * manifest ownership model and the package's ownership record only.
 */
final class UpgradeApplyEngine
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $base = $root ?? (\function_exists('base_path') ? base_path() : getcwd());
        $this->root = rtrim(str_replace('\\', '/', (string) $base), '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Promote the verified staged target over the live tree.
     *
     * @param  array<string,mixed>  $model  ownership model
     * @param  array<string,string>  $oldOwnedFiles  site's recorded ownership (rel=>sha)
     * @param  array<string,string>  $newOwnedFiles  package ownership (rel=>sha); derived from staging when empty
     * @return array{replaced_dirs:list<string>, replaced_files:list<string>,
     *               removed_stale:list<string>, pruned_dirs:list<string>,
     *               outcome:string, counts:array<string,int>}
     *
     * @throws UpgradeTransactionException with a verified outcome on any failure
     */
    public function apply(string $stagingDir, array $model, array $oldOwnedFiles, array $newOwnedFiles, ?string $txnDir = null): array
    {
        $txnDir ??= $this->root.'/storage/app/upgrades/engine-'.bin2hex(random_bytes(6));
        $txn = new UpgradeTransaction($this->root, $txnDir);
        $plan = $txn->plan($stagingDir, $model, $oldOwnedFiles, $newOwnedFiles);
        $txn->execute($plan);
        if (($problems = $txn->verifyCommitted($plan)) !== []) {
            $rollback = $txn->rollback();

            throw new UpgradeTransactionException('The promoted Core files could not be verified.', $rollback['outcome'], 'verify', ['problems' => $problems]);
        }
        $txn->finalize($plan, $model['replace_dirs'] ?? []);

        $roots = array_map(static fn ($d) => rtrim(CoreOwnership::normalize((string) $d), '/'), $model['replace_dirs'] ?? []);
        $files = array_map(static fn ($f) => CoreOwnership::normalize((string) $f), $model['replace_files'] ?? []);
        $replacedDirs = [];
        $replacedFiles = [];
        $removed = [];
        foreach ($plan['ops'] as $op) {
            if ($op['op'] === 'delete') {
                $removed[] = $op['rel'];

                continue;
            }
            if (\in_array($op['rel'], $files, true)) {
                $replacedFiles[] = $op['rel'];
            }
            foreach ($roots as $r) {
                if (str_starts_with($op['rel'], $r.'/')) {
                    $replacedDirs[$r] = $r;
                }
            }
        }

        return [
            'replaced_dirs' => array_values($replacedDirs),
            'replaced_files' => $replacedFiles,
            'removed_stale' => $removed,
            'pruned_dirs' => [],
            'outcome' => UpgradeOutcome::COMMITTED_VERIFIED,
            'counts' => $plan['counts'],
        ];
    }
}
