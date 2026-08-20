<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Services\SlugManager;

/**
 * Rebuild the public-URL resolver table (cms_slugs) from the per-locale
 * translation slugs (cms_content_translations / cms_term_translations).
 *
 * cms_slugs is normally kept in sync on every content/term save; this command
 * is a repair/backfill tool for installs whose public-slug rows are missing or
 * stale (e.g. after a manual DB import or a permalink-base change). It never
 * rewrites a translation's bare slug — only the cms_slugs prefix/full_path are
 * recomputed from the current permalink bases.
 */
class SlugRebuildCommand extends Command
{
    protected $signature = 'tncms:slugs:rebuild';

    protected $description = 'Rebuild cms_slugs public URLs from per-locale content/term translation slugs';

    public function handle(SlugManager $slugs): int
    {
        $this->info('Rebuilding localized public slugs from translation tables…');

        $written = $slugs->rebuildAllPublicSlugs();

        $this->info("Done. {$written} public slug row(s) written.");

        return self::SUCCESS;
    }
}
