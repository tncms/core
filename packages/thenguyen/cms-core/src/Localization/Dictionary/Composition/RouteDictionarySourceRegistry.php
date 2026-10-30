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
    /**
     * CORE-PLATFORM-SEAMS-R1: when bound by Core, registrations from plugin code are
     * identity-checked (a plugin registers only its own `plugin:{slug}` source at
     * {@see PluginRouteDictionarySource::PLUGIN_PRIORITY}). Core, host and test code are
     * trusted. Null keeps the frozen P6.3 behaviour for direct construction.
     */
    public function __construct(private readonly ?\TheNguyen\CMS\Content\Extension\CallSitePlugin $callSite = null) {}

    /** @var array<int, RouteDictionarySourceInterface> */
    private array $sources = [];

    private bool $locked = false;

    /** @var array<string, list<string>> source id => refusal reasons (Core policy) */
    private array $refused = [];

    /** @var list<array{source: string, key: string, locale: string}> */
    private array $fallbacks = [];

    /**
     * Register a build-time source. Fails closed once the registry has been read (composed).
     */
    public function register(RouteDictionarySourceInterface $source): void
    {
        if ($this->locked) {
            throw RouteDictionarySourceRegistryException::locked($source->id());
        }

        foreach ($this->sources as $existing) {
            if ($existing->id() === $source->id()) {
                throw RouteDictionarySourceRegistryException::duplicate($source->id());
            }
        }

        $caller = $this->callSite?->resolve() ?? false;

        if ($caller !== false && (! $source instanceof PluginRouteDictionarySource
            || $source->pluginId() !== $caller
            || $source->priority() !== PluginRouteDictionarySource::PLUGIN_PRIORITY)) {
            throw RouteDictionarySourceRegistryException::identity($source->id(), $caller);
        }

        $this->sources[] = $source;
    }

    /**
     * Record Core's evaluation of the registered sources (CORE-LOTTERY-P05-SEAMS-1).
     *
     * @param  array<string, list<string>>  $refused
     * @param  list<array{source: string, key: string, locale: string}>  $fallbacks
     */
    public function recordEvaluation(array $refused, array $fallbacks): void
    {
        $this->refused = $refused;
        $this->fallbacks = $fallbacks;
    }

    /** @return array<string, list<string>> source id => reasons; refused sources contribute nothing */
    public function refusals(): array
    {
        return $this->refused;
    }

    public function isRefused(string $sourceId): bool
    {
        return isset($this->refused[$sourceId]);
    }

    /** @return list<array{source: string, key: string, locale: string}> missing-locale fallbacks to the route key */
    public function fallbacks(): array
    {
        return $this->fallbacks;
    }

    /** Whether the Dictionary has been composed (no further registration possible). */
    public function isLocked(): bool
    {
        return $this->locked;
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
