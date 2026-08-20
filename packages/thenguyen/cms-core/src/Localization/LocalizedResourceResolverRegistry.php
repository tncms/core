<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

use TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract;
use TheNguyen\CMS\Localization\Exceptions\LocalizedResourceResolverException;

/**
 * CORE-L10N.1B — the single registry of localized-resource resolvers, shared by Core resources
 * and plugins.
 *
 * Registration is fail-fast: a duplicate resolver key throws immediately (never a silent
 * override). Selection is deterministic: for a given context the supporting resolver with the
 * highest priority wins, ties broken by registration order, so ONE canonical resource resolves
 * through exactly ONE resolver. It holds no plugin classes by import — resolvers are injected as
 * contract instances.
 */
final class LocalizedResourceResolverRegistry
{
    /** @var array<string, LocalizedResourceResolverContract> */
    private array $resolvers = [];

    /** Insertion order, for deterministic tie-breaking. */
    private int $sequence = 0;

    /** @var array<string, int> */
    private array $order = [];

    public function register(LocalizedResourceResolverContract $resolver): void
    {
        $key = $resolver->key();

        if (isset($this->resolvers[$key])) {
            throw LocalizedResourceResolverException::duplicateKey($key);
        }

        $this->resolvers[$key] = $resolver;
        $this->order[$key] = $this->sequence++;
    }

    public function has(string $key): bool
    {
        return isset($this->resolvers[$key]);
    }

    /**
     * The single resolver that handles $context (highest priority, then earliest registered),
     * or null when none supports it.
     */
    public function select(LocalizationContext $context): ?LocalizedResourceResolverContract
    {
        $selected = null;
        $selectedPriority = null;
        $selectedOrder = null;

        foreach ($this->resolvers as $key => $resolver) {
            if (! $resolver->supports($context)) {
                continue;
            }

            $priority = $resolver->priority();
            $order = $this->order[$key];

            if ($selected === null
                || $priority > $selectedPriority
                || ($priority === $selectedPriority && $order < $selectedOrder)) {
                $selected = $resolver;
                $selectedPriority = $priority;
                $selectedOrder = $order;
            }
        }

        return $selected;
    }

    /**
     * Registered resolver keys, for diagnostics.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->resolvers);
    }

    /**
     * All registered resolvers keyed by key.
     *
     * @return array<string, LocalizedResourceResolverContract>
     */
    public function all(): array
    {
        return $this->resolvers;
    }
}
