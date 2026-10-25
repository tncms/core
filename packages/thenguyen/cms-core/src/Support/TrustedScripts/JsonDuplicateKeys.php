<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedScripts;

/**
 * Detects duplicate object keys in a JSON document (CORE-EXTENSION-TRUST S1).
 *
 * PHP's json_decode() silently keeps the LAST value of a repeated key, so a
 * manifest could show one script to a reviewer and run another. This scanner
 * walks the raw text and reports every key that occurs twice in the same
 * object. Keys are compared after JSON unescaping, so "a" and "a" are the
 * same key. The caller still validates the document with json_decode().
 */
final class JsonDuplicateKeys
{
    /**
     * @return list<string>|null Duplicate keys as "/path/key"; [] when none; null
     *                           when the text is not scannable JSON.
     */
    public static function find(string $json): ?array
    {
        $length = strlen($json);
        $duplicates = [];

        /** @var list<array{type: string, keys: array<string, true>, expectKey: bool, path: string, lastKey: ?string}> $stack */
        $stack = [];

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $end = self::stringEnd($json, $i);

                if ($end === null) {
                    return null;
                }

                $top = count($stack) - 1;

                if ($top >= 0 && $stack[$top]['type'] === 'object' && $stack[$top]['expectKey']) {
                    $key = json_decode(substr($json, $i, $end - $i + 1));

                    if (! is_string($key)) {
                        return null;
                    }

                    if (isset($stack[$top]['keys'][$key])) {
                        $duplicates[] = $stack[$top]['path'].'/'.$key;
                    }

                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expectKey'] = false;
                    $stack[$top]['lastKey'] = $key;
                }

                $i = $end;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $top = count($stack) - 1;
                $path = '';

                if ($top >= 0) {
                    $path = $stack[$top]['path'].'/'.($stack[$top]['type'] === 'object' ? (string) $stack[$top]['lastKey'] : '[]');
                }

                $stack[] = ['type' => $char === '{' ? 'object' : 'array', 'keys' => [], 'expectKey' => $char === '{', 'path' => $path, 'lastKey' => null];

                continue;
            }

            if ($char === '}' || $char === ']') {
                if ($stack === []) {
                    return null;
                }

                array_pop($stack);

                continue;
            }

            if ($char === ',') {
                $top = count($stack) - 1;

                if ($top >= 0 && $stack[$top]['type'] === 'object') {
                    $stack[$top]['expectKey'] = true;
                }
            }
        }

        return $stack === [] ? $duplicates : null;
    }

    /** Index of the closing quote of the string starting at $start, or null. */
    private static function stringEnd(string $json, int $start): ?int
    {
        $length = strlen($json);

        for ($j = $start + 1; $j < $length; $j++) {
            if ($json[$j] === '\\') {
                $j++;

                continue;
            }

            if ($json[$j] === '"') {
                return $j;
            }
        }

        return null;
    }
}
