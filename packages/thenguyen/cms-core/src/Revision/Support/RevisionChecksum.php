<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Support;

/**
 * Deterministic sha256 checksum of a localized field map.
 *
 * Canonicalization (recursive key-sort + stable JSON encoding) guarantees that
 * two snapshots with identical values always hash identically regardless of key
 * order or encoding drift — the "signature to flag drift" idiom used by the
 * payment/shipping selection hashers. This is what makes no-op edit detection
 * (skip a save that changed nothing) reliable.
 */
final class RevisionChecksum
{
    private function __construct() {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function forFields(array $fields): string
    {
        return hash('sha256', self::canonical($fields));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private static function canonical(array $fields): string
    {
        return (string) json_encode(
            self::normalize($fields),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Recursively sort associative-array keys so the encoded form is stable.
     */
    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }

        // Only re-key stable-order for string-keyed (associative) maps; lists
        // keep their order (order is meaningful in a list).
        if (self::isAssoc($normalized)) {
            ksort($normalized);
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $array
     */
    private static function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
