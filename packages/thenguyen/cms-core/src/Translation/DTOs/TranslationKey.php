<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * Immutable identifier for a translatable value.
 *
 * A key is an opaque, driver-agnostic address: an optional module `namespace`
 * (e.g. "ecommerce", "core") plus a `key` (free-form, often dotted). Drivers and
 * repositories decide how to map it onto their own storage. Engine-only — this
 * does not read or persist anything by itself.
 */
final class TranslationKey
{
    public function __construct(
        public readonly string $key,
        public readonly ?string $namespace = null,
    ) {
    }

    public static function make(string $key, ?string $namespace = null): self
    {
        return new self($key, $namespace);
    }

    /** Canonical string form: "namespace::key" or just "key". */
    public function toString(): string
    {
        return $this->namespace !== null && $this->namespace !== ''
            ? $this->namespace.'::'.$this->key
            : $this->key;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function withNamespace(?string $namespace): self
    {
        return new self($this->key, $namespace);
    }

    public function withKey(string $key): self
    {
        return new self($key, $this->namespace);
    }
}
