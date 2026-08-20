<?php

declare(strict_types=1);

namespace App\Search\Drivers;

use App\Search\Contracts\SearchDriverInterface;
use App\Search\SearchExecutionResult;
use App\Search\SearchFailure;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchResult;
use Throwable;

/**
 * Default execution driver (Phase 3.1.6N-C): fan the query out to each provider
 * that owns a requested type, collect their hits, and isolate failures.
 *
 * Every provider searches its OWN repositories — this driver never touches a
 * model, a query builder, or entity-specific logic. A provider that throws is
 * captured as a {@see SearchFailure}; the others still return their results.
 */
final class ProviderSearchDriver implements SearchDriverInterface
{
    /** Defensive per-provider ceiling so a misbehaving provider cannot flood the engine. */
    public const PROVIDER_CANDIDATE_LIMIT = 100;

    public function __construct(private readonly SearchRegistry $registry) {}

    public function name(): string
    {
        return 'provider';
    }

    public function execute(SearchQuery $query, array $types): SearchExecutionResult
    {
        $inScope = array_fill_keys($types, true);

        // Resolve the distinct providers owning at least one requested type.
        $providers = [];
        foreach ($types as $type) {
            $provider = $this->registry->providerFor($type);
            if ($provider !== null) {
                $providers[$provider->key()] = $provider;
            }
        }

        $results = [];
        $failures = [];

        foreach ($providers as $provider) {
            try {
                $taken = 0;
                foreach ($provider->search($query) as $hit) {
                    // Ignore anything that is not a real, in-scope result — a
                    // provider cannot inject hits for a type it does not own.
                    if (! $hit instanceof SearchResult || ! isset($inScope[$hit->type])) {
                        continue;
                    }

                    $results[] = $hit;

                    if (++$taken >= self::PROVIDER_CANDIDATE_LIMIT) {
                        break;
                    }
                }
            } catch (Throwable) {
                // Contain the failure; never surface internal error detail.
                $failures[] = new SearchFailure($provider->key(), 'Search provider failed.');
            }
        }

        return new SearchExecutionResult(array_values($results), array_values($failures));
    }
}
