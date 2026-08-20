<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

use TheNguyen\CMS\Localization\Dictionary\Exceptions\RouteKeyException;
use TheNguyen\CMS\Localization\Dictionary\ReservedRouteKeys;

/**
 * P6.3 — a Route Dictionary source contributed by a TN CMS plugin (build-time only).
 *
 * A plugin instantiates this in its service-provider boot and registers it with the
 * {@see RouteDictionarySourceRegistry}. It carries the plugin's identity, a merge priority
 * (higher wins, per the P6.2 precedence model), and a raw `key => [locale => segment]` payload
 * of localized static segments for the plugin's OWN Route Keys.
 *
 * Isolation (INV-DICTIONARY-04, mirroring {@see \TheNguyen\CMS\Localization\Dictionary\RouteKeyRegistry}):
 * a plugin registers segments for its own keys ONLY — reserved Platform keys ("products", "post",
 * …) are rejected eagerly at construction, so a plugin can never hijack core routing. Malformed
 * keys/segments are rejected downstream by the loader at Dictionary construction.
 *
 * No Runtime logic, no Loader logic, no projection, no validation beyond the reserved-key guard.
 */
final class PluginRouteDictionarySource implements RouteDictionarySourceInterface
{
    /** Default merge priority for plugin sources — above the core persistence base (0). */
    public const PLUGIN_PRIORITY = 100;

    /**
     * @param  string  $pluginId  Stable plugin identity (e.g. the plugin slug).
     * @param  array<string, array<string, string>>  $payload  key => [locale => segment].
     */
    public function __construct(
        private readonly string $pluginId,
        private readonly array $payload,
        private readonly int $priority = self::PLUGIN_PRIORITY,
    ) {
        $reserved = new ReservedRouteKeys;

        foreach (array_keys($payload) as $key) {
            if ($reserved->isReserved((string) $key)) {
                throw RouteKeyException::reserved((string) $key);
            }
        }
    }

    /** Unique, stable source identity — namespaced so it never collides with core/theme sources. */
    public function id(): string
    {
        return 'plugin:'.$this->pluginId;
    }

    public function pluginId(): string
    {
        return $this->pluginId;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
