<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Directory;

/**
 * Opaque keyset cursor for {@see TaxonomyDirectory::search()}.
 *
 * Token = base64url(JSON payload) "." base64url(HMAC-SHA256). The HMAC key is derived from the
 * application key, so a client can neither forge nor alter a cursor. The payload binds the cursor
 * to its scope (taxonomy, locale, translation policy, hash of the normalized query) and carries
 * only the last row's keyset position (sort_order, id) — never an offset. There is no expiry: the
 * position stays meaningful as long as the scope matches (see docs 03 for concurrency semantics).
 *
 * @internal Consumers treat the token as an opaque string.
 */
final class TermCursor
{
    public const VERSION = 1;

    public const MAX_LENGTH = 512;

    /** @param array{t: string, l: string, r: int, q: string} $scope */
    public static function encode(array $scope, int $sortOrder, int $id): string
    {
        $payload = self::b64((string) json_encode(['v' => self::VERSION] + $scope + ['so' => $sortOrder, 'id' => $id]));

        return $payload.'.'.self::b64(self::sign($payload));
    }

    /**
     * @param  array{t: string, l: string, r: int, q: string}  $scope
     * @return array{so: int, id: int}
     *
     * @throws InvalidTermDirectoryRequest
     */
    public static function decode(string $token, array $scope): array
    {
        if ($token === '' || strlen($token) > self::MAX_LENGTH || substr_count($token, '.') !== 1) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_CURSOR);
        }

        [$payload, $signature] = explode('.', $token, 2);

        if (! hash_equals(self::b64(self::sign($payload)), $signature)) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_CURSOR);
        }

        $data = json_decode((string) self::unb64($payload), true);

        if (! is_array($data) || ($data['v'] ?? null) !== self::VERSION
            || ! is_int($data['so'] ?? null) || ! is_int($data['id'] ?? null) || $data['id'] < 1) {
            throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_CURSOR);
        }

        foreach ($scope as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                throw new InvalidTermDirectoryRequest(InvalidTermDirectoryRequest::REASON_CURSOR_SCOPE);
            }
        }

        return ['so' => $data['so'], 'id' => $data['id']];
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'cms.taxonomy.directory.cursor|'.$payload, 'cms.taxonomy.directory|'.(string) config('app.key'), true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
