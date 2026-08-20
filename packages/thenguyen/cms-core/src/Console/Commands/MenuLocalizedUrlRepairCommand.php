<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Console\Commands;

use Illuminate\Console\Command;
use TheNguyen\CMS\Models\MenuItem;

/**
 * Backfill per-locale custom menu-item URLs from the legacy shared column.
 *
 * Before localized menu URLs (v1.0.0-beta.6.1), a custom item's URL lived only
 * in cms_menu_items.url — a single shared column. This command seeds the DEFAULT
 * locale's translation URL from that column when it is still empty, so legacy
 * custom items become per-locale editable without losing data.
 *
 * It is intentionally conservative:
 *  - Custom items only; entity-linked items derive their URL from the localized
 *    slug at render time and need no repair (they self-heal).
 *  - The default locale only — other locales are never fabricated (the old
 *    schema could not store them, so their intended value is unknown).
 *  - Existing translation URLs are never overwritten.
 */
class MenuLocalizedUrlRepairCommand extends Command
{
    protected $signature = 'tncms:menus:repair-localized-urls {--dry-run : Report what would change without writing}';

    protected $description = 'Backfill per-locale custom menu-item URLs from the legacy shared column';

    public function handle(): int
    {
        $locale = app('cms.language')->defaultCode();
        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;

        MenuItem::query()
            ->where('type', 'custom')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->with(['translations' => fn ($q) => $q->where('locale', $locale)])
            ->chunkById(200, function ($items) use ($locale, $dryRun, &$repaired): void {
                foreach ($items as $item) {
                    $translation = $item->translations->firstWhere('locale', $locale);

                    // Skip when the default locale already has its own URL.
                    if ($translation !== null && $translation->url !== null && $translation->url !== '') {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Would seed item #{$item->id} ({$locale}) -> {$item->url}");
                        $repaired++;

                        continue;
                    }

                    $item->translations()->updateOrCreate(
                        ['locale' => $locale],
                        [
                            'title' => $translation?->title ?? $item->displayTitle($locale),
                            'url' => $item->url,
                        ],
                    );

                    $repaired++;
                }
            });

        $verb = $dryRun ? 'would be repaired' : 'repaired';
        $this->info("Done. {$repaired} custom menu item(s) {$verb} for the default locale ({$locale}).");

        return self::SUCCESS;
    }
}
