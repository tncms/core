<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchManagerInterface;
use App\Search\Contracts\SearchProvider;
use App\Search\Drivers\ProviderSearchDriver;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3.1.6N-C — Search Execution Engine.
 *
 * Exercises the SearchManager end-to-end over fake providers: resolution,
 * merge/rank, failure isolation, bounded pagination, locale forwarding, and
 * safe handling of invalid scopes. No entity-specific providers are created —
 * the engine stays generic.
 */
class SearchExecutionEngineTest extends TestCase
{
    // See SearchFoundationContractTest: the dev install marker forces a settings
    // read at boot; RefreshDatabase provisions the schema. The engine itself
    // performs no queries.
    use RefreshDatabase;

    private function registry(): SearchRegistry
    {
        $registry = app(SearchRegistry::class);
        $registry->flush();

        return $registry;
    }

    private function manager(): SearchManagerInterface
    {
        return app(SearchManagerInterface::class);
    }

    private function definition(string $type, string $label = 'Thing'): SearchableEntityDefinition
    {
        return new class($type, $label) implements SearchableEntityDefinition
        {
            public function __construct(private string $type, private string $label) {}

            public function type(): string
            {
                return $this->type;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function modelClass(): string
            {
                return User::class;
            }

            public function searchableFields(): array
            {
                return ['name'];
            }

            public function supportsLocales(): bool
            {
                return true;
            }

            public function visibilityRules(): array
            {
                return ['published', 'public', 'non-deleted'];
            }

            public function resolveTitle(object $entity, string $locale): string
            {
                return $this->label;
            }

            public function resolveUrl(object $entity, string $locale): ?string
            {
                return '/'.$this->type;
            }
        };
    }

    /**
     * @param  list<SearchableEntityDefinition>  $definitions
     */
    private function provider(string $key, array $definitions, \Closure $search): SearchProvider
    {
        return new class($key, $definitions, $search) implements SearchProvider
        {
            /** @param list<SearchableEntityDefinition> $definitions */
            public function __construct(
                private string $key,
                private array $definitions,
                private \Closure $search,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function definitions(): array
            {
                return $this->definitions;
            }

            public function search(SearchQuery $query): array
            {
                return ($this->search)($query);
            }
        };
    }

    private function hit(string $type, int $id, float $score, string $locale = 'en'): SearchResult
    {
        return new SearchResult($type, $id, $type.' '.$id, '', '/'.$type.'/'.$id, $score, $locale);
    }

    // 1. Manager executes and returns a response.
    public function test_manager_executes_query(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 3.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('shoes', 'en'));

        $this->assertCount(1, $response->results);
        $this->assertSame('product', $response->results[0]->type);
        $this->assertSame(['product'], $response->executedScopes);
        $this->assertSame(1, $response->total);
        $this->assertFalse($response->hasFailures());
    }

    // 1b. Manager is bound.
    public function test_manager_is_bound(): void
    {
        $this->assertSame(app(SearchManagerInterface::class), app('cms.search.manager'));
    }

    // 2. Provider resolution — only providers owning a requested type run.
    public function test_only_owning_providers_execute(): void
    {
        $registry = $this->registry();
        $ran = [];

        $registry->register($this->provider('ecommerce', [$this->definition('product')], function () use (&$ran): array {
            $ran['ecommerce'] = true;

            return [$this->hit('product', 1, 1.0)];
        }));
        $registry->register($this->provider('knowledge-library', [$this->definition('book')], function () use (&$ran): array {
            $ran['knowledge-library'] = true;

            return [$this->hit('book', 1, 1.0)];
        }));

        $this->manager()->search(SearchQuery::create('x', 'en', ['product']));

        $this->assertArrayHasKey('ecommerce', $ran);
        $this->assertArrayNotHasKey('knowledge-library', $ran);
    }

    // 3. Multiple providers merge and rank by score.
    public function test_multiple_providers_merge_and_rank(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 3.0),
        ]));
        $registry->register($this->provider('knowledge-library', [$this->definition('book')], fn (): array => [
            $this->hit('book', 1, 9.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertCount(2, $response->results);
        $this->assertSame('book', $response->results[0]->type);   // 9.0 first
        $this->assertSame('product', $response->results[1]->type); // 3.0 second
    }

    // 4. Failure isolation — one provider throwing does not break the others.
    public function test_failure_isolation(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (): array {
            throw new RuntimeException('DB exploded: secret internal detail');
        }));
        $registry->register($this->provider('knowledge-library', [$this->definition('book')], fn (): array => [
            $this->hit('book', 1, 5.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertCount(1, $response->results);
        $this->assertSame('book', $response->results[0]->type);
        $this->assertTrue($response->hasFailures());
        $this->assertSame('ecommerce', $response->failures[0]->provider);
    }

    // 4b. Failure messages never leak internal detail.
    public function test_failure_message_is_generic(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (): array {
            throw new RuntimeException('SQLSTATE secret internal detail');
        }));

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertStringNotContainsString('SQLSTATE', $response->failures[0]->message);
        $this->assertStringNotContainsString('secret', $response->failures[0]->message);
        $this->assertSame(['Search provider failed.'], $response->warnings());
    }

    // 5. Ranking orders strictly by score, descending.
    public function test_ranking_orders_by_score(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 1.0),
            $this->hit('product', 2, 5.0),
            $this->hit('product', 3, 3.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 1, 10));

        $this->assertSame([2, 3, 1], array_map(static fn (SearchResult $r) => $r->id, $response->results));
    }

    // 6. Pagination bounds the page and reports totals.
    public function test_pagination_is_bounded(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 5.0),
            $this->hit('product', 2, 4.0),
            $this->hit('product', 3, 3.0),
            $this->hit('product', 4, 2.0),
            $this->hit('product', 5, 1.0),
        ]));

        $page1 = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 1, 2));
        $this->assertSame([1, 2], array_map(static fn (SearchResult $r) => $r->id, $page1->results));
        $this->assertSame(5, $page1->total);
        $this->assertSame(3, $page1->totalPages());

        $page3 = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 3, 2));
        $this->assertSame([5], array_map(static fn (SearchResult $r) => $r->id, $page3->results));
    }

    // 6b. No unlimited loading — a flooding provider is capped defensively.
    public function test_provider_candidate_limit_caps_results(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (): array {
            $hits = [];
            for ($i = 1; $i <= 150; $i++) {
                $hits[] = $this->hit('product', $i, (float) $i);
            }

            return $hits;
        }));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 1, 20));

        $this->assertSame(ProviderSearchDriver::PROVIDER_CANDIDATE_LIMIT, $response->total);
        $this->assertLessThanOrEqual(20, count($response->results));
    }

    // 7. Locale forwarding — the query locale reaches providers and results.
    public function test_locale_is_forwarded(): void
    {
        $registry = $this->registry();
        $seen = null;
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (SearchQuery $q) use (&$seen): array {
            $seen = $q->locale;

            return [$this->hit('product', 1, 1.0, $q->locale)];
        }));

        $response = $this->manager()->search(SearchQuery::create('giày', 'vi'));

        $this->assertSame('vi', $seen);
        $this->assertSame('vi', $response->results[0]->locale);
    }

    // 8. Invalid-type-only queries resolve to an empty response, no error.
    public function test_unknown_type_only_yields_empty(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 1.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['ghost']));

        $this->assertTrue($response->isEmpty());
        $this->assertSame(0, $response->total);
        $this->assertSame([], $response->executedScopes);
    }

    // 8b. Mixed valid/invalid types execute only the known ones.
    public function test_mixed_types_execute_known_only(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 1.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['product', 'ghost']));

        $this->assertSame(['product'], $response->executedScopes);
        $this->assertCount(1, $response->results);
    }

    // 9. Empty keyword short-circuits to an empty response.
    public function test_empty_keyword_yields_empty(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 1.0),
        ]));

        $response = $this->manager()->search(SearchQuery::create('   ', 'en'));

        $this->assertTrue($response->isEmpty());
        $this->assertSame(0, $response->total);
    }

    // 10. A provider cannot inject hits for a type it does not own.
    public function test_out_of_scope_hits_are_dropped(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => [
            $this->hit('product', 1, 2.0),
            $this->hit('book', 99, 9.0), // not owned / not in scope
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['product']));

        $this->assertCount(1, $response->results);
        $this->assertSame('product', $response->results[0]->type);
    }

    // 11. Foundation regression — the registry and scopes still work.
    public function test_foundation_regression(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')], fn (): array => []));

        $scopes = $registry->scopes();
        $this->assertSame('all', $scopes[0]->key);
        $this->assertSame('product', $scopes[1]->key);
    }

    // 12. Knowledge Library / Ecommerce stability — no OPTIONAL entity providers
    // auto-wired. A fresh app boot registers the platform plus the Core default
    // "content" provider (CORE-SEARCH-1 GAP 1); optional plugin providers
    // (product/book/author) only appear when their plugin is active.
    public function test_only_core_content_provider_is_registered_by_default(): void
    {
        $registry = app(SearchRegistry::class);

        $this->assertSame(['content'], array_keys($registry->providers()));
    }
}
