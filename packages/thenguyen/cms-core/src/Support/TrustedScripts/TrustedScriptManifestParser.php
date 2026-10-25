<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedScripts;

/**
 * Parses the `trusted_scripts` block of a plugin.json (CORE-EXTENSION-TRUST S1).
 *
 * Strict schema, fail-closed:
 *  - Any duplicate JSON key anywhere in the manifest rejects ALL of the plugin's
 *    trusted scripts (json_decode would silently keep the last value).
 *  - `trusted_scripts` must be a JSON object of at most MAX_SCRIPTS entries.
 *  - Each entry is validated as a whole; one bad field rejects that entry only.
 *  - Unknown fields are errors, not ignored.
 *
 * Nothing is ever fetched, enqueued or rendered here.
 */
final class TrustedScriptManifestParser
{
    public const MAX_SCRIPTS = 16;

    public const DIRECTIVES = ['child-src', 'connect-src', 'frame-src', 'img-src', 'script-src', 'style-src', 'worker-src'];

    private const FIELDS = ['url', 'position', 'attributes', 'deps', 'csp', 'purpose'];

    private const KEY_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const DEP_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}$/';

    private const MAX_DEPS = 16;

    private const MAX_SOURCES_PER_DIRECTIVE = 16;

    private const MAX_PURPOSE = 500;

    private const REFERRER_POLICIES = [
        'no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
        'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url',
    ];

    /**
     * @return array{declarations: list<TrustedScriptDeclaration>, errors: list<array{owner: string, key: ?string, reason: string}>}
     */
    public static function parse(string $owner, string $rawJson): array
    {
        $result = ['declarations' => [], 'errors' => []];

        $data = json_decode($rawJson, true);

        if (! is_array($data) || ! array_key_exists('trusted_scripts', $data)) {
            return $result;
        }

        $duplicates = JsonDuplicateKeys::find($rawJson);

        if ($duplicates === null || $duplicates !== []) {
            $result['errors'][] = ['owner' => $owner, 'key' => null, 'reason' => 'duplicate json key in manifest'];

            return $result;
        }

        $scripts = $data['trusted_scripts'];

        // A JSON object decodes to an associative array; a list (or scalar) is invalid.
        if (! is_array($scripts) || ($scripts !== [] && array_is_list($scripts))) {
            $result['errors'][] = ['owner' => $owner, 'key' => null, 'reason' => 'trusted_scripts must be an object'];

            return $result;
        }

        if (count($scripts) > self::MAX_SCRIPTS) {
            $result['errors'][] = ['owner' => $owner, 'key' => null, 'reason' => 'too many trusted scripts'];

            return $result;
        }

        foreach ($scripts as $key => $entry) {
            $key = (string) $key;
            $error = null;
            $declaration = self::entry($owner, $key, $entry, $error);

            if ($declaration === null) {
                $result['errors'][] = ['owner' => $owner, 'key' => self::safeKey($key), 'reason' => (string) $error];

                continue;
            }

            $result['declarations'][] = $declaration;
        }

        return $result;
    }

    private static function entry(string $owner, string $key, mixed $entry, ?string &$error): ?TrustedScriptDeclaration
    {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            return self::fail($error, 'invalid key');
        }

        if (! is_array($entry) || ($entry !== [] && array_is_list($entry))) {
            return self::fail($error, 'entry must be an object');
        }

        foreach (array_keys($entry) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                return self::fail($error, 'unknown field');
            }
        }

        $url = is_string($entry['url'] ?? null) ? StrictHttpsUrl::normalize($entry['url']) : null;

        if ($url === null) {
            return self::fail($error, 'invalid url');
        }

        $origin = (string) StrictHttpsUrl::origin($url);

        $position = $entry['position'] ?? 'footer';

        if (! in_array($position, ['head', 'footer'], true)) {
            return self::fail($error, 'invalid position');
        }

        $attributes = self::attributes($entry['attributes'] ?? []);

        if ($attributes === null) {
            return self::fail($error, 'invalid attributes');
        }

        $deps = self::deps($entry['deps'] ?? []);

        if ($deps === null) {
            return self::fail($error, 'invalid deps');
        }

        $csp = self::csp($entry['csp'] ?? null);

        if ($csp === null) {
            return self::fail($error, 'invalid csp');
        }

        if (! in_array($origin, $csp['script-src'] ?? [], true)) {
            return self::fail($error, 'script-src must include the script origin');
        }

        $purpose = $entry['purpose'] ?? null;

        if (! is_string($purpose) || trim($purpose) === '' || mb_strlen($purpose) > self::MAX_PURPOSE
            || preg_match('/[\x00-\x1f\x7f]/', $purpose)) {
            return self::fail($error, 'invalid purpose');
        }

        return new TrustedScriptDeclaration(
            owner: $owner,
            key: $key,
            url: $url,
            origin: $origin,
            position: $position,
            attributes: $attributes,
            deps: $deps,
            csp: $csp,
            purpose: trim($purpose),
        );
    }

    /** @return array<string, bool|string>|null */
    private static function attributes(mixed $value): ?array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }

        $out = [];

        foreach ($value as $name => $attr) {
            $valid = match ($name) {
                'async', 'defer' => is_bool($attr),
                'crossorigin' => in_array($attr, ['anonymous', 'use-credentials'], true),
                'referrerpolicy' => in_array($attr, self::REFERRER_POLICIES, true),
                'integrity' => is_string($attr) && preg_match('/^(sha(256|384|512)-[A-Za-z0-9+\/]+={0,2})( sha(256|384|512)-[A-Za-z0-9+\/]+={0,2})*$/', $attr) === 1,
                default => false,
            };

            if (! $valid) {
                return null;
            }

            if ($attr !== false) {
                $out[(string) $name] = $attr;
            }
        }

        ksort($out);

        return $out;
    }

    /** @return list<string>|null */
    private static function deps(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_DEPS) {
            return null;
        }

        $out = [];

        foreach ($value as $dep) {
            if (! is_string($dep) || ! preg_match(self::DEP_PATTERN, $dep)
                || str_starts_with($dep, TrustedScriptDeclaration::HANDLE_PREFIX)) {
                return null;
            }

            if (! in_array($dep, $out, true)) {
                $out[] = $dep;
            }
        }

        return $out;
    }

    /** @return array<string, list<string>>|null */
    private static function csp(mixed $value): ?array
    {
        if (! is_array($value) || $value === [] || array_is_list($value)) {
            return null;
        }

        $out = [];

        foreach ($value as $directive => $sources) {
            if (! in_array($directive, self::DIRECTIVES, true)) {
                return null;
            }

            if (! is_array($sources) || $sources === [] || ! array_is_list($sources)
                || count($sources) > self::MAX_SOURCES_PER_DIRECTIVE) {
                return null;
            }

            $normalized = [];

            foreach ($sources as $source) {
                $origin = is_string($source) ? StrictHttpsUrl::normalizeOrigin($source) : null;

                if ($origin === null) {
                    return null;
                }

                $normalized[$origin] = true;
            }

            $list = array_keys($normalized);
            sort($list);
            $out[(string) $directive] = $list;
        }

        ksort($out);

        return $out;
    }

    private static function fail(?string &$error, string $reason): null
    {
        $error = $reason;

        return null;
    }

    private static function safeKey(string $key): string
    {
        return substr((string) preg_replace('/[^a-z0-9_-]/', '', strtolower($key)), 0, 64);
    }
}
