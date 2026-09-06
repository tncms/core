<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

/**
 * EG-9 — the single, coherent symbolic-reference resolver shared by every native
 * demo importer (categories, tags, posts, and the media/page refs they cross-link
 * to). Symbolic keys are the demo's IMPORT IDENTITY; they are never database ids
 * and never localized slugs, so a slug collision can never imply ownership.
 *
 * A reference is `{namespace}:{key}` with the documented namespaces below. The
 * resolver distinguishes, without ever collapsing to a boolean:
 *   - a resolved reference (namespace + key → local id);
 *   - an unresolved reference (valid namespace/key, no local object yet);
 *   - a wrong namespace (outside the documented set);
 *   - a malformed reference (no colon / empty part / illegal key charset);
 *   - a duplicate symbolic declaration (same namespace+key registered twice in
 *     one import run — the first registration wins).
 *
 * Keys share the demo-package slug charset (`[a-z0-9][a-z0-9_-]*`, case-insensitive)
 * so a key can never carry traversal, whitespace, or a second colon.
 */
final class DemoSymbolResolver
{
    /** The documented symbolic namespaces (order is stable/contractual). */
    public const NAMESPACES = ['media', 'category', 'tag', 'page', 'post'];

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_UNRESOLVED = 'unresolved';

    public const STATUS_INVALID_NAMESPACE = 'invalid_namespace';

    public const STATUS_MALFORMED = 'malformed';

    /** @var array<string, int> canonical `namespace:key` => local id */
    private array $map = [];

    /**
     * @param  array<string, int>  $map  canonical `namespace:key` => local id
     */
    public function __construct(array $map = [])
    {
        foreach ($map as $canonical => $id) {
            if (is_string($canonical) && is_int($id)) {
                $this->map[$canonical] = $id;
            }
        }
    }

    /**
     * Build a resolver from a persisted `imported_keys` provenance map, which
     * mixes namespaced entries (`page:landing`, `category:news`, …) with the
     * legacy bare media keys the media importer stored (`blog-hero` => id). A bare
     * key is normalized to the `media` namespace so cross-object refs resolve on
     * re-import.
     *
     * @param  array<string, int|string>  $importedKeys
     */
    public static function fromImportedKeys(array $importedKeys): self
    {
        $resolver = new self;

        foreach ($importedKeys as $key => $id) {
            if (! is_string($key) || ! (is_int($id) || (is_string($id) && ctype_digit($id)))) {
                continue;
            }

            $parsed = self::parse($key);

            if ($parsed !== null) {
                $resolver->register($parsed[0], $parsed[1], (int) $id);

                continue;
            }

            // Legacy bare media key.
            if (self::isKey($key)) {
                $resolver->register('media', $key, (int) $id);
            }
        }

        return $resolver;
    }

    /**
     * Parse a reference into [namespace, key], or null when malformed. Wrong
     * (undocumented) namespaces still parse structurally — {@see resolve()}
     * classifies them as INVALID_NAMESPACE — but an empty part, whitespace, a
     * second colon, or an illegal key charset is rejected here.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $reference): ?array
    {
        $pos = strpos($reference, ':');

        if ($pos === false) {
            return null;
        }

        $namespace = substr($reference, 0, $pos);
        $key = substr($reference, $pos + 1);

        if ($namespace === '' || ! self::isKey($key)) {
            return null;
        }

        return [$namespace, $key];
    }

    /**
     * Register a symbolic key → local id. Returns false when the (namespace, key)
     * pair is already registered in this run (duplicate declaration); the first
     * registration is never overwritten.
     */
    public function register(string $namespace, string $key, int $id): bool
    {
        $canonical = $namespace.':'.$key;

        if (array_key_exists($canonical, $this->map)) {
            return false;
        }

        $this->map[$canonical] = $id;

        return true;
    }

    public function has(string $namespace, string $key): bool
    {
        return array_key_exists($namespace.':'.$key, $this->map);
    }

    public function idFor(string $namespace, string $key): ?int
    {
        return $this->map[$namespace.':'.$key] ?? null;
    }

    /**
     * Classify and (when possible) resolve a raw reference string.
     *
     * @return array{status: string, namespace: ?string, key: ?string, id: ?int}
     */
    public function resolve(string $reference): array
    {
        $parsed = self::parse($reference);

        if ($parsed === null) {
            return ['status' => self::STATUS_MALFORMED, 'namespace' => null, 'key' => null, 'id' => null];
        }

        [$namespace, $key] = $parsed;

        if (! in_array($namespace, self::NAMESPACES, true)) {
            return ['status' => self::STATUS_INVALID_NAMESPACE, 'namespace' => $namespace, 'key' => $key, 'id' => null];
        }

        $id = $this->idFor($namespace, $key);

        return [
            'status' => $id !== null ? self::STATUS_RESOLVED : self::STATUS_UNRESOLVED,
            'namespace' => $namespace,
            'key' => $key,
            'id' => $id,
        ];
    }

    /**
     * The canonical map (`namespace:key` => id) — used to fold importer results
     * back into the persisted provenance `imported_keys`.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->map;
    }

    private static function isKey(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $value) === 1;
    }
}
