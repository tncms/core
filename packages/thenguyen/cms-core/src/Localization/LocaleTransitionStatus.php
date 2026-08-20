<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N A2 — the outcome of a locale transition, for diagnostics.
 *
 * Changed   = a valid, different locale was applied and persisted (event emitted).
 * Unchanged = a valid locale equal to the current one (persisted, no event).
 * Rejected  = an empty / inactive / unsupported locale (nothing persisted, no event).
 */
enum LocaleTransitionStatus: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case Rejected = 'rejected';
}
