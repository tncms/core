<?php

declare(strict_types=1);

namespace App\Search;

use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchProvider;
use App\Search\Exceptions\DuplicateSearchScopeException;
use App\Search\Exceptions\InvalidSearchableDefinitionException;

/**
 * The single catalog of everything searchable across the CMS (Phase 3.1.6N-B
 * foundation). Bound as a singleton (`cms.search`); plugins register their
 * {@see SearchProvider} from their own service-provider `boot()`.
 *
 * The registry is a pure catalog: it validates and indexes providers/definitions,
 * lists the selectable scopes, resolves the provider or definition for a type,
 * and rejects duplicate identifiers. It does NOT execute searches — orchestration,
 * merge and ranking across providers belong to a later search manager (Phase
 * 3.1.6N-C). No plugin owns this class; it lives in the host application so global
 * search has no single-plugin owner.
 */
final class SearchRegistry
{
    private const SLUG_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    /** @var array<string, SearchProvider> Keyed by provider key. */
    private array $providers = [];

    /** @var array<string, SearchableEntityDefinition> Keyed by entity type. */
    private array $definitions = [];

    /** @var array<string, string> type => owning provider key. */
    private array $owners = [];

    /**
     * Register a provider and all of its entity definitions atomically. If any
     * definition is invalid or collides with an existing type, nothing is
     * registered — a failing provider never leaves a half-registered scope, which
     * keeps providers isolated from one another.
     *
     * Idempotent: re-registering the exact same provider instance is a no-op so
     * boot is safe to run more than once.
     *
     * @throws InvalidSearchableDefinitionException
     * @throws DuplicateSearchScopeException
     */
    public function register(SearchProvider $provider): void
    {
        $key = $provider->key();

        if ($key === '' || preg_match(self::SLUG_PATTERN, $key) !== 1) {
            throw InvalidSearchableDefinitionException::badProviderKey($key);
        }

        if (isset($this->providers[$key])) {
            if ($this->providers[$key] === $provider) {
                return; // Same instance re-registered: no-op.
            }

            throw DuplicateSearchScopeException::provider($key);
        }

        $definitions = $provider->definitions();

        if ($definitions === []) {
            throw InvalidSearchableDefinitionException::emptyProvider($key);
        }

        // Validate every definition BEFORE mutating so registration is atomic.
        foreach ($definitions as $definition) {
            $this->validateDefinition($key, $definition);
        }

        $this->providers[$key] = $provider;

        foreach ($definitions as $definition) {
            $type = $definition->type();
            $this->definitions[$type] = $definition;
            $this->owners[$type] = $key;
        }
    }

    /** @return array<string, SearchProvider> */
    public function providers(): array
    {
        return $this->providers;
    }

    /** @return array<string, SearchableEntityDefinition> Keyed by entity type. */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function hasType(string $type): bool
    {
        return isset($this->definitions[$type]);
    }

    /** Fails safe: unknown types return null rather than throwing. */
    public function definitionFor(string $type): ?SearchableEntityDefinition
    {
        return $this->definitions[$type] ?? null;
    }

    /** Fails safe: unknown types return null rather than throwing. */
    public function providerFor(string $type): ?SearchProvider
    {
        $owner = $this->owners[$type] ?? null;

        return $owner !== null ? ($this->providers[$owner] ?? null) : null;
    }

    /**
     * The selectable scopes for the "Search in:" dropdown: the synthetic "All"
     * scope first, then one single-type scope per registered definition, in
     * registration order.
     *
     * @return list<SearchScope>
     */
    public function scopes(): array
    {
        $types = array_keys($this->definitions);
        $scopes = [SearchScope::all($types)];

        foreach ($this->definitions as $type => $definition) {
            $scopes[] = new SearchScope($type, $definition->label(), [$type]);
        }

        return $scopes;
    }

    /**
     * Expand a set of requested scope keys into concrete, known entity types.
     * Empty input or "all" resolves to every registered type; unknown types are
     * silently dropped (fail safe) so a stale or hostile scope key can never
     * crash a search.
     *
     * @param  array<int, string>  $requested
     * @return list<string>
     */
    public function resolveTypes(array $requested): array
    {
        $known = array_keys($this->definitions);

        $requested = array_values(array_unique(array_map(
            static fn ($type): string => strtolower(trim((string) $type)),
            $requested,
        )));

        if ($requested === [] || in_array(SearchScope::ALL, $requested, true)) {
            return $known;
        }

        return array_values(array_filter(
            $requested,
            static fn (string $type): bool => in_array($type, $known, true),
        ));
    }

    /** Reset the registry (test helper). */
    public function flush(): void
    {
        $this->providers = [];
        $this->definitions = [];
        $this->owners = [];
    }

    /**
     * @throws InvalidSearchableDefinitionException
     * @throws DuplicateSearchScopeException
     */
    private function validateDefinition(string $providerKey, SearchableEntityDefinition $definition): void
    {
        $type = $definition->type();

        if ($type === '' || preg_match(self::SLUG_PATTERN, $type) !== 1) {
            throw InvalidSearchableDefinitionException::badType($type);
        }

        if ($definition->label() === '') {
            throw InvalidSearchableDefinitionException::missingLabel($type);
        }

        if ($definition->searchableFields() === []) {
            throw InvalidSearchableDefinitionException::noFields($type);
        }

        if ($definition->visibilityRules() === []) {
            throw InvalidSearchableDefinitionException::noVisibilityRules($type);
        }

        $model = $definition->modelClass();

        if ($model === '' || ! class_exists($model)) {
            throw InvalidSearchableDefinitionException::badModel($type, $model);
        }

        $owner = $this->owners[$type] ?? null;

        if ($owner !== null && $owner !== $providerKey) {
            throw DuplicateSearchScopeException::type($type, $owner, $providerKey);
        }
    }
}
