<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * EG-9 — a deterministic content fingerprint of a normalized DECLARATIVE demo
 * object (a category/tag/post entry). It is the provenance identity for
 * "owned unchanged" vs "owned source changed": the SAME declarative source
 * always fingerprints identically across retries, a CHANGED source fingerprints
 * differently.
 *
 * It never incorporates database ids, autoincrement values, or timestamps — only
 * the declarative source. Maps are key-sorted recursively so JSON key order is
 * irrelevant; list order is preserved because it carries meaning (e.g. category
 * ordering, tag order). The result is `sha256:<hex>` and never leaks source values.
 */
final class DemoFingerprint
{
    public static function of(mixed $data): string
    {
        $normalized = self::normalize($data);

        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'sha256:'.hash('sha256', $json !== false ? $json : '');
    }

    /**
     * Recursively sort associative-array keys; preserve list order. Scalars pass
     * through unchanged.
     */
    private static function normalize(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        if (array_is_list($data)) {
            return array_map(static fn ($v) => self::normalize($v), $data);
        }

        ksort($data);

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::normalize($value);
        }

        return $out;
    }
}
