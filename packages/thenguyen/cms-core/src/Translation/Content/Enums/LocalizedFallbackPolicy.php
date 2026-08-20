<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Content\Enums;

/**
 * How a localized field falls back when the requested locale has no value
 * (Phase 8.4).
 *
 * A policy is NOT a fallback implementation — it only SELECTS which of the
 * engine's canonical chain stages apply, via {@see stages()}. The one fallback
 * implementation remains the engine's {@see \TheNguyen\CMS\Translation\Support\FallbackChain}.
 *
 *   - Chain       : requested → site fallback → default → raw (the full chain).
 *   - DefaultOnly : requested → default locale only.
 *   - Strict      : requested locale only — no cross-locale fallback (e.g. slugs).
 *   - Raw         : requested → the raw/legacy source only.
 */
enum LocalizedFallbackPolicy: string
{
    case Chain = 'chain';
    case DefaultOnly = 'default';
    case Strict = 'strict';
    case Raw = 'raw';

    /**
     * The ordered engine fallback-chain stage names this policy maps to. These are
     * exactly the stages understood by the engine's FallbackChain — no new stages.
     *
     * @return array<int, string>
     */
    public function stages(): array
    {
        return match ($this) {
            self::Chain => ['requested', 'site_fallback', 'default', 'raw'],
            self::DefaultOnly => ['requested', 'default'],
            self::Strict => ['requested'],
            self::Raw => ['requested', 'raw'],
        };
    }
}
