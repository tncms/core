<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary;

use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException;

/**
 * The single registry of Route Key identities (P5H.0A, Phase B / INV-DICTIONARY-01).
 *
 * Reserved Platform keys are pre-seeded and immutable. A plugin registers its OWN keys
 * (identities only — never localized segments, INV-DICTIONARY-04) and the registry enforces:
 *
 *   • reserved keys cannot be registered/redefined by a plugin (fails eagerly);
 *   • every key identity is unique (no duplicates, no aliases).
 *
 * This registry holds IDENTITIES only. The localized-segment projection is a separate,
 * Platform-owned concern ({@see RouteSegmentDictionaryContract}); the registry never stores
 * or returns a localized segment.
 */
final class RouteKeyRegistry
{
    /** @var array<string, RouteKey> */
    private array $keys = [];

    public function __construct(private readonly ReservedRouteKeys $reserved)
    {
        foreach ($this->reserved->all() as $key) {
            $this->keys[$key->value] = $key;
        }
    }

    /**
     * Register a plugin-owned Route Key identity. Fails eagerly on a reserved or duplicate key.
     */
    public function register(RouteKey $key): void
    {
        if ($this->reserved->isReserved($key)) {
            throw RouteKeyException::reserved($key->value);
        }

        if (isset($this->keys[$key->value])) {
            throw RouteKeyException::duplicate($key->value);
        }

        $this->keys[$key->value] = $key;
    }

    public function has(RouteKey|string $key): bool
    {
        $value = $key instanceof RouteKey ? $key->value : RouteKey::of($key)->value;

        return isset($this->keys[$value]);
    }

    /** @return array<int, RouteKey> */
    public function all(): array
    {
        return array_values($this->keys);
    }
}
