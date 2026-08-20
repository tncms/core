<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

/**
 * The frozen set of reserved Platform Route Keys (P5H.0A, Phase F / INV-DICTIONARY-04).
 *
 * These identities are owned by the Platform. A plugin MUST NOT register or redefine them
 * ({@see RouteKeyRegistry::register()} rejects a reserved key). Third-party plugins register
 * their OWN distinct keys (e.g. "course", "event"); they never reuse a reserved identity and
 * never register localized segments.
 */
final class ReservedRouteKeys
{
    /** @var list<string> */
    private const KEYS = [
        'products',
        'product-category',
        'brand',
        'page',
        'post',
        'tag',
        'search',
        'author',
        'contact',
        'login',
        'register',
        'docs',
        'media',
    ];

    /** @return array<int, RouteKey> */
    public function all(): array
    {
        return array_map(static fn (string $key): RouteKey => RouteKey::of($key), self::KEYS);
    }

    /** @return list<string> */
    public function values(): array
    {
        return self::KEYS;
    }

    public function isReserved(RouteKey|string $key): bool
    {
        $value = $key instanceof RouteKey ? $key->value : RouteKey::of($key)->value;

        return in_array($value, self::KEYS, true);
    }
}
