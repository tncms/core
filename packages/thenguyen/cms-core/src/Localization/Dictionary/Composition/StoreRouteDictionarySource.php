<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

use TheNguyen\CMS\Localization\Dictionary\Persistence\RouteDictionaryStoreInterface;
use TheNguyen\CMS\Localization\Dictionary\PlatformRouteDictionary;

/**
 * P6.2 — the base Route Dictionary source, backed by the P6.1 persistence store.
 *
 * This is the FIRST registered source (lowest priority). Its payload is the persisted dictionary,
 * falling back to the frozen seed ({@see PlatformRouteDictionary::data()}) when the store is empty
 * — so a fresh install composes to the byte-identical dictionary. Higher-priority sources (theme,
 * plugin, project — later phases) override on top of this base.
 */
final class StoreRouteDictionarySource implements RouteDictionarySourceInterface
{
    public function __construct(
        private readonly RouteDictionaryStoreInterface $store,
        private readonly int $priority = 0,
        private readonly string $id = 'core.persistence',
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function payload(): array
    {
        $data = $this->store->exists() ? $this->store->load() : [];

        return $data !== [] ? $data : PlatformRouteDictionary::data();
    }
}
