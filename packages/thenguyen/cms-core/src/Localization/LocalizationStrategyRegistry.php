<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Localization\Contracts\LocalizationStrategyContract;
use TheNguyen\CMS\Localization\Exceptions\LocalizationStrategyException;

/**
 * CORE-L10N.1B — the registry of Core-owned localization strategies.
 *
 * Registration is fail-fast: a duplicate strategy key throws immediately (never a silent
 * override). Resolving the active strategy for an unknown configured key throws (fail closed)
 * rather than guessing. The registry is the single place strategies are discovered, and it
 * exposes diagnostics.
 */
final class LocalizationStrategyRegistry
{
    /** @var array<string, LocalizationStrategyContract> */
    private array $strategies = [];

    public function register(LocalizationStrategyContract $strategy): void
    {
        $key = $strategy->key();

        if (isset($this->strategies[$key])) {
            throw LocalizationStrategyException::duplicateKey($key);
        }

        $this->strategies[$key] = $strategy;
    }

    public function has(string $key): bool
    {
        return isset($this->strategies[$key]);
    }

    /** Resolve a strategy by key, or fail closed when it is not registered. */
    public function resolve(string $key): LocalizationStrategyContract
    {
        return $this->strategies[$key] ?? throw LocalizationStrategyException::unknown($key);
    }

    /** Resolve the active strategy for the given configured key (fail closed on unknown). */
    public function active(string $configuredKey): LocalizationStrategyContract
    {
        return $this->resolve($configuredKey);
    }

    /**
     * Registered strategy keys, for diagnostics.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * All registered strategies keyed by key.
     *
     * @return array<string, LocalizationStrategyContract>
     */
    public function all(): array
    {
        return $this->strategies;
    }
}
