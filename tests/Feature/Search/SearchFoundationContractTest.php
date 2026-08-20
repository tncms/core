<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchProvider;
use App\Search\Exceptions\DuplicateSearchScopeException;
use App\Search\Exceptions\InvalidSearchableDefinitionException;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchResult;
use App\Search\SearchScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 3.1.6N-B — Shared Search Foundation Contract.
 *
 * Exercises the host-owned registry, the query/result/scope value objects, and
 * the provider/definition extension points. No database: the foundation is pure
 * contract wiring, so these tests boot the framework only to prove the singleton
 * binding and never touch storage.
 */
class SearchFoundationContractTest extends TestCase
{
    // The dev-only install marker (storage/app/tncms-installed) makes the CMS
    // boot as installed and read cms_settings; RefreshDatabase provisions the
    // schema so the app boots. The foundation itself performs no queries.
    use RefreshDatabase;

    private function registry(): SearchRegistry
    {
        $registry = app(SearchRegistry::class);
        $registry->flush();

        return $registry;
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $visibility
     */
    private function definition(
        string $type,
        string $label = 'Thing',
        array $fields = ['name'],
        bool $locales = true,
        array $visibility = ['published', 'public', 'non-deleted'],
        string $model = User::class,
    ): SearchableEntityDefinition {
        return new class($type, $label, $fields, $locales, $visibility, $model) implements SearchableEntityDefinition
        {
            /**
             * @param  list<string>  $fields
             * @param  list<string>  $visibility
             */
            public function __construct(
                private string $type,
                private string $label,
                private array $fields,
                private bool $locales,
                private array $visibility,
                private string $model,
            ) {}

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
                return $this->model;
            }

            public function searchableFields(): array
            {
                return $this->fields;
            }

            public function supportsLocales(): bool
            {
                return $this->locales;
            }

            public function visibilityRules(): array
            {
                return $this->visibility;
            }

            public function resolveTitle(object $entity, string $locale): string
            {
                return $this->label.' '.$locale;
            }

            public function resolveUrl(object $entity, string $locale): ?string
            {
                return '/'.$this->type.'/'.$locale;
            }
        };
    }

    /**
     * @param  list<SearchableEntityDefinition>  $definitions
     */
    private function provider(
        string $key,
        array $definitions,
        ?\Closure $search = null,
    ): SearchProvider {
        return new class($key, $definitions, $search) implements SearchProvider
        {
            /**
             * @param  list<SearchableEntityDefinition>  $definitions
             */
            public function __construct(
                private string $key,
                private array $definitions,
                private ?\Closure $search,
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
                return $this->search !== null ? ($this->search)($query) : [];
            }
        };
    }

    // 1. Registry registration.
    public function test_registers_provider_and_exposes_it(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')]));

        $this->assertArrayHasKey('ecommerce', $registry->providers());
        $this->assertTrue($registry->hasType('product'));
        $this->assertSame('Product', $registry->definitionFor('product')?->label());
        $this->assertSame('ecommerce', $registry->providerFor('product')?->key());
    }

    // 1b. Singleton is bound and host-owned.
    public function test_registry_is_bound_as_singleton(): void
    {
        $this->assertSame(app(SearchRegistry::class), app(SearchRegistry::class));
        $this->assertSame(app(SearchRegistry::class), app('cms.search'));
    }

    // 2. Duplicate detection — same type from a different provider is rejected.
    public function test_rejects_duplicate_type_from_another_provider(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')]));

        $this->expectException(DuplicateSearchScopeException::class);
        $registry->register($this->provider('rogue', [$this->definition('product', 'Clone')]));
    }

    // 2b. Duplicate detection — different instance under an existing provider key.
    public function test_rejects_duplicate_provider_key(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')]));

        $this->expectException(DuplicateSearchScopeException::class);
        $registry->register($this->provider('ecommerce', [$this->definition('brand')]));
    }

    // 2c. Idempotent — the SAME instance re-registered is a no-op.
    public function test_same_instance_reregistration_is_noop(): void
    {
        $registry = $this->registry();
        $provider = $this->provider('ecommerce', [$this->definition('product')]);

        $registry->register($provider);
        $registry->register($provider);

        $this->assertCount(1, $registry->providers());
        $this->assertCount(1, $registry->definitions());
    }

    // 3. Invalid types / definitions fail validation.
    public function test_rejects_invalid_type_slug(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidSearchableDefinitionException::class);
        $registry->register($this->provider('ecommerce', [$this->definition('Not A Slug')]));
    }

    public function test_rejects_empty_searchable_fields(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidSearchableDefinitionException::class);
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product', [])]));
    }

    public function test_rejects_missing_visibility_rules(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidSearchableDefinitionException::class);
        $registry->register($this->provider('ecommerce', [
            $this->definition('product', 'Product', ['name'], true, []),
        ]));
    }

    public function test_rejects_nonexistent_model(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidSearchableDefinitionException::class);
        $registry->register($this->provider('ecommerce', [
            $this->definition('product', 'Product', ['name'], true, ['published'], 'App\\Nope\\Missing'),
        ]));
    }

    public function test_rejects_empty_provider(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidSearchableDefinitionException::class);
        $registry->register($this->provider('ecommerce', []));
    }

    // 3b. Atomic registration — a bad definition registers NOTHING.
    public function test_failed_registration_is_atomic(): void
    {
        $registry = $this->registry();

        try {
            $registry->register($this->provider('ecommerce', [
                $this->definition('product', 'Product'),
                $this->definition('bad type'),
            ]));
            $this->fail('Expected InvalidSearchableDefinitionException.');
        } catch (InvalidSearchableDefinitionException) {
            // expected
        }

        $this->assertCount(0, $registry->providers());
        $this->assertFalse($registry->hasType('product'));
    }

    // 4. Unknown types fail safe (no throw) when resolving.
    public function test_unknown_types_resolve_safely(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')]));

        $this->assertNull($registry->definitionFor('ghost'));
        $this->assertNull($registry->providerFor('ghost'));
        $this->assertSame([], $registry->resolveTypes(['ghost']));
        $this->assertSame(['product'], $registry->resolveTypes([]));
        $this->assertSame(['product'], $registry->resolveTypes(['all']));
        $this->assertSame(['product'], $registry->resolveTypes(['product', 'ghost']));
    }

    // 5. Result contract shape — a reference, not a model.
    public function test_search_result_is_a_reference(): void
    {
        $result = new SearchResult(
            type: 'product',
            id: 42,
            title: 'Running Shoes',
            excerpt: 'Comfortable running shoes.',
            url: '/products/running-shoes',
            score: 12.5,
            locale: 'vi',
            metadata: ['matched_by' => ['name']],
        );

        $array = $result->toArray();
        $this->assertSame('product', $array['type']);
        $this->assertSame(42, $array['id']);
        $this->assertSame('/products/running-shoes', $array['url']);
        $this->assertArrayNotHasKey('model', $array);
    }

    // 5b. Query contract — pagination is always bounded.
    public function test_query_normalizes_and_bounds(): void
    {
        $query = SearchQuery::create(
            keyword: '  running shoes  ',
            locale: 'vi',
            types: ['Product', 'product', 'article'],
            page: 0,
            perPage: 9999,
        );

        $this->assertSame('running shoes', $query->keyword);
        $this->assertSame(['product', 'article'], $query->types);
        $this->assertSame(1, $query->page);
        $this->assertSame(SearchQuery::MAX_PER_PAGE, $query->perPage);
        $this->assertFalse($query->isEmpty());
        $this->assertTrue($query->wantsType('product'));
        $this->assertFalse($query->wantsType('book'));
        $this->assertTrue(SearchQuery::create('x', 'en')->wantsAll());
    }

    // 6. Multi-entity provider — one provider, several types.
    public function test_multi_entity_provider_registers_all_types(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('knowledge-library', [
            $this->definition('book', 'Book'),
            $this->definition('author', 'Author'),
            $this->definition('quote', 'Quote'),
        ]));

        $this->assertCount(3, $registry->definitions());
        $this->assertSame('knowledge-library', $registry->providerFor('author')?->key());

        $scopes = $registry->scopes();
        $this->assertSame(SearchScope::ALL, $scopes[0]->key);
        $this->assertSame(['book', 'author', 'quote'], $scopes[0]->types);
        $this->assertCount(4, $scopes); // All + 3 types.
    }

    // 7. Isolation — one plugin's scope cannot affect another's.
    public function test_providers_are_isolated(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')]));
        $registry->register($this->provider('knowledge-library', [$this->definition('book', 'Book')]));

        $this->assertSame('ecommerce', $registry->providerFor('product')?->key());
        $this->assertSame('knowledge-library', $registry->providerFor('book')?->key());
        $this->assertNotSame(
            $registry->providerFor('product'),
            $registry->providerFor('book'),
        );

        // A rejected duplicate from a third plugin leaves both intact.
        try {
            $registry->register($this->provider('rogue', [$this->definition('book', 'Clone')]));
        } catch (DuplicateSearchScopeException) {
            // expected
        }

        $this->assertCount(2, $registry->providers());
        $this->assertSame('knowledge-library', $registry->providerFor('book')?->key());
    }

    // 8. Locale passing — the query locale reaches the provider unchanged.
    public function test_locale_is_passed_to_provider(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider(
            'ecommerce',
            [$this->definition('product', 'Product')],
            search: fn (SearchQuery $q): array => [
                new SearchResult('product', 1, 'Giày', '', null, 1.0, $q->locale),
            ],
        ));

        $results = $registry->providerFor('product')?->search(SearchQuery::create('giày', 'vi'));

        $this->assertSame('vi', $results[0]->locale);
    }

    // 9. Unpublished protection — providers return only visible references.
    public function test_provider_excludes_unpublished(): void
    {
        $catalog = [
            ['id' => 1, 'title' => 'Live', 'published' => true],
            ['id' => 2, 'title' => 'Draft', 'published' => false],
        ];

        $registry = $this->registry();
        $registry->register($this->provider(
            'ecommerce',
            [$this->definition('product', 'Product')],
            search: function (SearchQuery $q) use ($catalog): array {
                $hits = [];
                foreach ($catalog as $row) {
                    if ($row['published'] === true) {
                        $hits[] = new SearchResult('product', $row['id'], $row['title'], '', null, 1.0, $q->locale);
                    }
                }

                return $hits;
            },
        ));

        $results = $registry->providerFor('product')?->search(SearchQuery::create('x', 'en'));

        $this->assertCount(1, $results);
        $this->assertSame('Live', $results[0]->title);
    }
}
