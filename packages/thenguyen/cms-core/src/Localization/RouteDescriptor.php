<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization;

/**
 * CORE-L10N.1B — an immutable description of a route target as pure routing FACTS.
 *
 * A {@see \TheNguyen\CMS\Localization\Contracts\LocalizedResourceResolverContract} produces a
 * RouteDescriptor for a given resource + target locale. The descriptor carries only what the
 * URL generator needs downstream: the canonical resource identity, the route name, and the
 * (possibly locale-specific, e.g. translated-slug) route parameters.
 *
 * It must NEVER contain — per the architecture lock — a generated URL, a locale prefix, a
 * domain, session logic, or HTTP-persistence logic. Strategies transform descriptors into
 * URLs; they never mutate a descriptor. Accordingly this object exposes no setters/withers:
 * a resolver builds a fresh descriptor per locale.
 */
final class RouteDescriptor
{
    /**
     * @param  array<string, scalar|null>  $routeParameters  Route parameters for the resolved
     *                                                        route (e.g. a translated slug). Never a URL/prefix.
     * @param  array<string, mixed>  $metadata  Presentation-neutral facts.
     * @param  ?string  $canonicalPath  An optional prefix-free, root-relative canonical path
     *                                  (e.g. '/blog/bai-viet') for resources whose path is not
     *                                  built from a named route. It carries the resolved slug but
     *                                  NEVER a locale prefix, domain, or absolute URL — the
     *                                  strategy applies the prefix.
     */
    public function __construct(
        public readonly string $resolverKey,
        public readonly string $canonicalType,
        public readonly string|int|null $canonicalId,
        public readonly string $routeName = '',
        public readonly array $routeParameters = [],
        public readonly array $metadata = [],
        public readonly ?string $canonicalPath = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolver_key' => $this->resolverKey,
            'canonical_type' => $this->canonicalType,
            'canonical_id' => $this->canonicalId,
            'route_name' => $this->routeName,
            'route_parameters' => $this->routeParameters,
            'metadata' => $this->metadata,
            'canonical_path' => $this->canonicalPath,
        ];
    }
}
