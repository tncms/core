<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Admin\Enums;

/**
 * The per-locale status of one localized field value (Phase 8.3).
 *
 * The admin layer distinguishes four states so it can tell a genuinely absent
 * translation apart from a deliberately blank one:
 *
 *   - Missing : the locale key is absent from state (never authored).
 *   - Null    : the locale key is present but null (explicitly cleared — will be
 *               removed on save).
 *   - Empty   : the locale key is present but '' (an authored empty string).
 *   - Filled  : a non-empty string.
 *
 * Only {@see self::Filled} counts as a completed translation.
 */
enum LocaleValueStatus: string
{
    case Missing = 'missing';
    case Null = 'null';
    case Empty = 'empty';
    case Filled = 'filled';

    /** A usable, completed translation (non-empty). */
    public function isComplete(): bool
    {
        return $this === self::Filled;
    }

    /** No usable translation yet (missing, cleared, or blank). */
    public function isIncomplete(): bool
    {
        return ! $this->isComplete();
    }
}
