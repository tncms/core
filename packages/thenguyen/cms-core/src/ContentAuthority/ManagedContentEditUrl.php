<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use TheNguyen\CMS\Models\Content;

/**
 * Record-aware editor redirect for extension-managed content
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * For content whose `managed_by` marker is set, Core asks the
 * `cms.content.edit_url` filter (value null, args: Content, context) where the
 * owning extension's editor lives. Only a same-site URL is accepted: a
 * root-relative path ("/admin/...", not "//host") or an absolute http(s) URL
 * whose host equals the application's. Anything else — including a throwing
 * filter, which the hook layer turns into the default null — yields null, and
 * the native editor then refuses to open the record (fail closed).
 */
final class ManagedContentEditUrl
{
    public static function for(Content $content): ?string
    {
        if ($content->managed_by === null) {
            return null;
        }

        $url = apply_filters('cms.content.edit_url', null, $content, hook_context([
            'content' => $content,
            'owner' => $content->managed_by,
        ]));

        return is_string($url) ? self::sameSite($url) : null;
    }

    public static function sameSite(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F\\\\]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? null : $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = array_filter([
            strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            app()->bound('request') ? strtolower((string) request()->getHost()) : '',
        ]);

        return $host !== '' && in_array($host, $allowed, true) ? $url : null;
    }
}
