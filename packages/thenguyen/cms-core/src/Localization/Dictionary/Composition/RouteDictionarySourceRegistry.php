<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

use TheNguyen\CMS\Localization\Dictionary\Composition\Exceptions\RouteDictionarySourceRegistryException;

/**
 * P6.3 — the boot-time collection point for plugin-contributed Route Dictionary sources.
 *
 * Plugins register a {@see RouteDictionarySourceInterface} here during their service-provider
 * boot (see the ExtensionManager two-pass: providers register BEFORE any plugin routes are
 * generated). The {@see RouteDictionaryComposer} singleton then collects {@see all()} alongside
 * the persistence base source and merges everything into ONE canonical payload.
 *
 * The Runtime is unaware of this registry. Plugins contribute build-time sources only — they
 * never touch the Composer, the Loader, or the immutable Runtime Dictionary.
 *
 * Ordering does NOT affect the composed result — the composer decides winners by (priority, id),
 * frozen in P6.2. This registry only guarantees the source SET is complete before composition,
 * and it locks on first read so a late (request-time) registration fails closed rather than
 * silently missing the Runtime.
 */
final class RouteDictionarySourceRegistry
{
    /** @var array<int, RouteDictionarySourceInterface> */
    private array $sources = [];

    private bool $locked = false;

    /**
     * Register a build-time source. Fails closed once the registry has been read (composed).
     */
    public function register(RouteDictionarySourceInterface $source): void
    {
        if ($this->locked) {
            throw RouteDictionarySourceRegistryException::locked($source->id());
        }

        $this->sources[] = $source;
    }

    /**
     * All registered sources. The first call LOCKS the registry — the composed Dictionary is
     * built from exactly this set; nothing may register afterwards.
     *
     * @return array<int, RouteDictionarySourceInterface>
     */
    public function all(): array
    {
        $this->locked = true;

        return $this->sources;
    }
}
