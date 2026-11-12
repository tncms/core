<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\Html;

/**
 * The bounded ARIA allowlist for sanitized user content (CORE-HTML-SANITIZER-A11Y-R1 §5).
 *
 * Exactly three attributes, each limited to an element set and a value grammar. Everything else under
 * `aria-*` is outside the contract and is removed by the sanitizer.
 */
final class AccessibleAttributePolicy
{
    public const MAX_LABEL_LENGTH = 160;

    public const MAX_IDREFS = 4;

    /** Four maximum-length ids plus three separators. */
    public const MAX_IDREF_BYTES = self::MAX_IDREFS * SafeHtmlId::MAX_LENGTH + self::MAX_IDREFS - 1;

    /** Elements whose roles support an author-provided accessible name. */
    private const NAMEABLE = ['a', 'nav', 'section', 'article', 'aside', 'main', 'ul', 'ol', 'table', 'figure'];

    private const ELEMENTS = [
        'aria-label' => self::NAMEABLE,
        'aria-labelledby' => self::NAMEABLE,
        'aria-current' => ['a', 'li'],
    ];

    private const CURRENT_VALUES = ['page', 'step', 'location', 'date', 'time', 'true', 'false'];

    private function __construct() {}

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::ELEMENTS);
    }

    public static function allows(string $tag, string $name): bool
    {
        return in_array($tag, self::ELEMENTS[$name] ?? [], true);
    }

    /** Plain, visible text: valid UTF-8, not blank, no control/format/separator characters, bounded length. */
    public static function isValidLabel(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8')
            && preg_match('/^[\s\p{Z}]*$/u', $value) !== 1
            && preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value) !== 1
            && mb_strlen($value, 'UTF-8') <= self::MAX_LABEL_LENGTH;
    }

    public static function isValidCurrent(string $value): bool
    {
        return in_array($value, self::CURRENT_VALUES, true);
    }

    /**
     * Parses a canonical IDREF list: 1–4 unique `cms-content-*` SafeHtmlId tokens separated by exactly one
     * ASCII space, at most {@see MAX_IDREF_BYTES} bytes.
     *
     * @return list<string>|null the tokens, or null when the value is not canonical
     */
    public static function idRefTokens(string $value): ?array
    {
        if (strlen($value) > self::MAX_IDREF_BYTES) {
            return null;
        }

        $tokens = explode(' ', $value);

        if (count($tokens) > self::MAX_IDREFS || count(array_unique($tokens)) !== count($tokens)) {
            return null;
        }

        foreach ($tokens as $token) {
            if (! SafeHtmlId::isValid($token)) {
                return null;
            }
        }

        return $tokens;
    }
}
