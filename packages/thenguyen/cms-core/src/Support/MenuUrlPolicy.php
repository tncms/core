<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * CORE-MENU-URL-1 — the single canonical authority for menu navigation URL
 * safety. Menu custom URLs are untrusted navigation data even when entered by an
 * authenticated administrator: they flow verbatim through {@see \TheNguyen\CMS\Services\MenuManager::tree()}
 * / {@see \frontend_menu()} into theme `href` sinks, so an unsafe scheme such as
 * `javascript:` placed in `href` becomes an executable stored-XSS sink.
 *
 * This policy classifies the *navigation scheme* of a URL (not arbitrary
 * substrings) and is reused by write-time validation, the public hydration
 * boundary, and first-party render sinks so there is ONE allowlist, not several.
 *
 * The classification mirrors {@see \TheNguyen\CMS\Services\HtmlSanitizer::isSafeUrl()}
 * (the proven rich-content policy) for the navigation case (no `data:` is ever
 * linkable in a menu):
 *   - trim, then strip ASCII control/space bytes (\x00-\x20) BEFORE the scheme
 *     test so obfuscation like "java\nscript:" / "\tjavascript:" cannot smuggle a
 *     dangerous scheme past the check;
 *   - an explicit scheme is only safe when it is one of http/https/mailto/tel;
 *   - any other explicit scheme (javascript:, data:, vbscript:, file:, blob:,
 *     and unknown schemes) is rejected;
 *   - a value with NO scheme (relative path, root-relative, query, fragment, or
 *     protocol-relative //host) is a safe navigation target.
 *
 * Note on encoding: this policy does NOT percent-decode or HTML-entity-decode the
 * value. A percent-encoded colon (e.g. "javascript%3Aalert(1)") does not form a
 * navigation scheme for the browser, so it is a harmless relative URL; and HTML
 * numeric/named entity attempts (e.g. "&#106;avascript:") are neutralised by the
 * Blade attribute escaping at the render sink (the leading "&" is escaped to
 * "&amp;", so the browser never decodes it back into a scheme). Decoding here and
 * re-emitting would risk introducing a double-decode vulnerability, so we classify
 * the value exactly as the browser will treat it for navigation.
 */
final class MenuUrlPolicy
{
    /**
     * Explicit URL schemes that are safe to place in a navigation href.
     *
     * @var array<int, string>
     */
    public const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * True when the URL is safe to emit as an executable navigation href.
     *
     * Empty / whitespace-only values are NOT linkable (there is nothing to
     * navigate to) but they are not dangerous either; callers treat them as
     * non-linkable via {@see self::sanitize()}.
     */
    public static function isSafe(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Strip ASCII controls and spaces before the scheme test so a dangerous
        // scheme cannot be smuggled past with embedded control characters.
        $probe = preg_replace('/[\x00-\x20]+/', '', $url) ?? $url;

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $matches) === 1) {
            return in_array(strtolower($matches[1]), self::SAFE_SCHEMES, true);
        }

        // No explicit scheme → relative/root-relative/query/fragment/protocol-relative.
        return true;
    }

    /**
     * Return the URL unchanged when it is a safe navigation target, or null when
     * it must not be emitted as an executable href. The stored value is never
     * rewritten — rejection is a presentation decision, not a data migration.
     */
    public static function sanitize(?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        return self::isSafe($url) ? $url : null;
    }
}
