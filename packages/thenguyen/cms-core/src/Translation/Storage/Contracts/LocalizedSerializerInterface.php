<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Contracts;

use TheNguyen\CMS\Translation\DTOs\LocalizedValue;

/**
 * Deterministic (de)serialization of a {@see LocalizedValue} to/from a storable
 * string payload.
 *
 * The contract guarantees **stable output regardless of locale insertion order**:
 * two values with the same locale/string pairs serialize to byte-identical
 * payloads. This is what backends that store the map as a single blob
 * (future JSON/YAML/Remote) rely on, and what makes stored payloads diffable and
 * content-hashable. Deserialization is tolerant — malformed input yields an empty
 * value, never an exception.
 */
interface LocalizedSerializerInterface
{
    public function serialize(LocalizedValue $value): string;

    public function deserialize(?string $payload): LocalizedValue;
}
