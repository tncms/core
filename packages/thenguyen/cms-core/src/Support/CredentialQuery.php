<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Query parameters that carry credentials or signed-URL proof. They never
 * belong in a public canonical URL: the sitemap refuses URLs carrying them and
 * canonical redirects drop them.
 */
final class CredentialQuery
{
    private const NAME = '/^(?:signature|expires|token|access_token|api_token|auth_token|password|secret)$/';

    public static function isCredential(string $name): bool
    {
        return preg_match(self::NAME, strtolower(rawurldecode($name))) === 1;
    }

    public static function carries(string $query): bool
    {
        foreach (explode('&', $query) as $pair) {
            if (self::isCredential(explode('=', $pair, 2)[0])) {
                return true;
            }
        }

        return false;
    }

    /** The query string without credential parameters (raw pairs kept byte-identical), or '' when nothing is left. */
    public static function strip(?string $query): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $kept = array_filter(
            explode('&', $query),
            static fn (string $pair): bool => $pair !== '' && ! self::isCredential(explode('=', $pair, 2)[0]),
        );

        return implode('&', $kept);
    }
}
