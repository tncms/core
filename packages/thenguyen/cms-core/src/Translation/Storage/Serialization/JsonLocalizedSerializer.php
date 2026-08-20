<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Serialization;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedSerializerInterface;

/**
 * JSON implementation of {@see LocalizedSerializerInterface}.
 *
 * Determinism comes from sorting locale keys before encoding, so the payload is
 * identical no matter the order locales were added. The optional `raw` source is
 * included only when present, keeping payloads minimal and stable. Decoding is
 * fully tolerant: any non-JSON / wrong-shape input yields an empty value.
 *
 * Shape: {"values":{"en":"…","vi":"…"},"raw":"…"?}
 */
final class JsonLocalizedSerializer implements LocalizedSerializerInterface
{
    public function serialize(LocalizedValue $value): string
    {
        $values = $value->toArray();
        ksort($values); // deterministic regardless of insertion order

        $payload = ['values' => (object) $values];

        if ($value->raw !== null) {
            $payload['raw'] = $value->raw;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : '{"values":{}}';
    }

    public function deserialize(?string $payload): LocalizedValue
    {
        if (! is_string($payload) || trim($payload) === '') {
            return new LocalizedValue([]);
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return new LocalizedValue([]);
        }

        $values = is_array($decoded['values'] ?? null) ? $decoded['values'] : [];
        $raw = array_key_exists('raw', $decoded) && is_string($decoded['raw']) ? $decoded['raw'] : null;

        return new LocalizedValue($values, $raw);
    }
}
