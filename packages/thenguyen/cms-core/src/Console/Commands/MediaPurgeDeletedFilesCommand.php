<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Services\MediaManager;

/**
 * Compensation for the media deletion lifecycle (CORE-P12-1): removes files that still exist
 * for soft-deleted media rows, e.g. because the filesystem refused the removal after the row
 * deletion committed, or the row was trashed before Core removed files. It uses the same safety
 * rules as MediaManager::delete(): only regular files inside public/uploads, never a file that a
 * live row still references. Rows are never changed. Safe to re-run.
 */
final class MediaPurgeDeletedFilesCommand extends Command
{
    protected $signature = 'cms:media:purge-deleted-files {--dry-run : Report the files that would be removed without removing them}';

    protected $description = 'Remove leftover files of soft-deleted media rows (safe, idempotent).';

    public function handle(MediaManager $media): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $removed = 0;
        $kept = 0;

        Media::onlyTrashed()->orderBy('id')->chunkById(500, function ($rows) use ($media, $dryRun, &$removed, &$kept): void {
            foreach ($rows as $row) {
                if ($media->purgeableFile($row) === null) {
                    $kept += is_string($row->path) && $row->path !== '' && is_file(public_path($row->path)) ? 1 : 0;

                    continue;
                }
                if ($dryRun) {
                    $removed++;
                    $this->line("would remove: {$row->path} (media #{$row->getKey()})");

                    continue;
                }
                $media->purgeFile($row) ? $removed++ : $kept++;
            }
        });

        $verb = $dryRun ? 'would be removed' : 'removed';
        $this->info("{$removed} file(s) {$verb}; {$kept} kept (unsafe path, still referenced, or not removable).");

        return self::SUCCESS;
    }
}
