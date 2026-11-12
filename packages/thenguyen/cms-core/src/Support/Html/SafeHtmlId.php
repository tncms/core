<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Html;

/**
 * The only `id` / IDREF grammar accepted in sanitized authored content (CORE-HTML-SANITIZER-A11Y-R1 §3).
 *
 * Core reserves the `cms-content-` namespace for safe anchors and accessibility relationships inside sanitized
 * authored content: `cms-content-` + lowercase ASCII letter/digit segments separated by single hyphens, at most
 * 64 bytes. Every other id is removed, so authored content can never claim an editor, runtime, theme or
 * extension id. Runtime code must never use this namespace. Values are matched exactly, never trimmed or
 * case-folded.
 */
final class SafeHtmlId
{
    public const PREFIX = 'cms-content-';

    public const MAX_LENGTH = 64;

    public const PATTERN = '/^cms-content-[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    private function __construct() {}

    public static function isValid(string $value): bool
    {
        return strlen($value) <= self::MAX_LENGTH && preg_match(self::PATTERN, $value) === 1;
    }
}
