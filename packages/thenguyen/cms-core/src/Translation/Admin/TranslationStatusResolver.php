<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin;

use TheNguyen\CMS\Translation\Admin\Enums\LocaleValueStatus;

/**
 * Derives translation-completeness signals for a localized field (Phase 8.3):
 * per-locale status, the missing/completed locale lists, and a completion ratio.
 * Powers the status indicator and missing-translation badge. Pure — no storage,
 * no fallback (a fallback value does not make a locale "translated").
 */
final class TranslationStatusResolver
{
    /**
     * @return array<string, LocaleValueStatus> code => status (enabled-locale ordered)
     */
    public function statuses(LocalizedFieldState $state, LocaleOptions $locales): array
    {
        $statuses = [];
        foreach ($locales->codes() as $code) {
            $statuses[$code] = $state->status($code);
        }

        return $statuses;
    }

    /**
     * @return array<int, string> enabled locales without a completed translation
     */
    public function missingLocales(LocalizedFieldState $state, LocaleOptions $locales): array
    {
        $missing = [];
        foreach ($locales->codes() as $code) {
            if ($state->status($code)->isIncomplete()) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    /**
     * @return array<int, string> enabled locales with a completed translation
     */
    public function completedLocales(LocalizedFieldState $state, LocaleOptions $locales): array
    {
        $completed = [];
        foreach ($locales->codes() as $code) {
            if ($state->status($code)->isComplete()) {
                $completed[] = $code;
            }
        }

        return $completed;
    }

    public function completionRatio(LocalizedFieldState $state, LocaleOptions $locales): float
    {
        $total = $locales->count();
        if ($total === 0) {
            return 1.0;
        }

        return count($this->completedLocales($state, $locales)) / $total;
    }

    public function isComplete(LocalizedFieldState $state, LocaleOptions $locales): bool
    {
        return $this->missingLocales($state, $locales) === [];
    }
}
