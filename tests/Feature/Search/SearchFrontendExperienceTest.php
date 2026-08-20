<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchProvider;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3.1.6N-D — Storefront Search Experience.
 *
 * Drives the public /search route end-to-end over fake providers: rendering,
 * scope selector, validation, empty states, pagination, and security (escaping,
 * no exception leakage, no open redirect). No entity-specific providers exist.
 */
class SearchFrontendExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SearchRegistry::class)->flush();
    }

    private function registry(): SearchRegistry
    {
        return app(SearchRegistry::class);
    }

    private function definition(string $type, string $label): SearchableEntityDefinition
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
     * @param  array<string, string>  $types  type => label
     */
    private function register(string $key, array $types, \Closure $search): void
    {
        $definitions = [];
        foreach ($types as $type => $label) {
            $definitions[] = $this->definition($type, $label);
        }

        $this->registry()->register(new class($key, $definitions, $search) implements SearchProvider
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
        });
    }

    private function hit(string $type, int $id, string $title, float $score, string $excerpt = '', ?string $url = null): SearchResult
    {
        return new SearchResult($type, $id, $title, $excerpt, $url ?? '/'.$type.'/'.$id, $score, 'en');
    }

    // === Route ===

    public function test_search_page_loads(): void
    {
        $this->registry();
        $this->get('/search')
            ->assertOk()
            ->assertSee('name="q"', false);
    }

    public function test_get_query_is_accepted(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => []);
        $this->get('/search?q=shoes')->assertOk();
    }

    public function test_route_has_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('cms.search');

        $this->assertNotNull($route);
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    // === Query validation ===

    public function test_short_keyword_shows_hint(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => [$this->hit('product', 1, 'X', 1.0)]);

        $this->get('/search?q=a')
            ->assertOk()
            ->assertSee('at least 2 characters')
            ->assertDontSee('tn-search-results', false);
    }

    public function test_pagination_limits_results_per_page(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], function (): array {
            $hits = [];
            for ($i = 1; $i <= 15; $i++) {
                $hits[] = $this->hit('product', $i, sprintf('Result-%02d', $i), (float) (15 - $i));
            }

            return $hits;
        });

        $this->get('/search?q=result')
            ->assertOk()
            ->assertSee('Result-01')
            ->assertSee('Result-12')
            ->assertDontSee('Result-13')
            ->assertSee('Next')
            ->assertSee('Page 1 of 2');
    }

    public function test_invalid_scope_is_handled_safely(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => [$this->hit('product', 1, 'Live', 5.0)]);

        $this->get('/search?q=test&scope=ghost')
            ->assertOk()
            ->assertSee('No results')
            ->assertDontSee('Live');
    }

    // === Scope selector ===

    public function test_scopes_are_registry_driven(): void
    {
        $this->register('multi', ['product' => 'Product', 'book' => 'Book'], fn (): array => []);

        $this->get('/search')
            ->assertOk()
            ->assertSee('All')
            ->assertSee('Product')
            ->assertSee('Book');
    }

    public function test_selected_scope_is_marked(): void
    {
        $this->register('multi', ['product' => 'Product', 'book' => 'Book'], fn (): array => []);

        $this->get('/search?q=xx&scope=book')
            ->assertOk()
            ->assertSee('value="book" selected', false);
    }

    // === Rendering ===

    public function test_results_render(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => [
            $this->hit('product', 1, 'Running Shoes', 5.0, 'Comfortable shoes', '/products/running-shoes'),
        ]);

        $this->get('/search?q=shoes')
            ->assertOk()
            ->assertSee('Running Shoes')
            ->assertSee('Comfortable shoes')
            ->assertSee('/products/running-shoes', false)
            ->assertSee('Product');
    }

    public function test_output_is_escaped(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => [
            $this->hit('product', 1, '<script>alert(1)</script>', 5.0),
        ]);

        $response = $this->get('/search?q=xss');
        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;', false);
    }

    public function test_empty_state_without_keyword(): void
    {
        $this->registry();
        $this->get('/search')
            ->assertOk()
            ->assertSee('Enter a keyword');
    }

    public function test_empty_state_without_results(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => []);

        $this->get('/search?q=zzzznothing')
            ->assertOk()
            ->assertSee('No results');
    }

    // === Security ===

    public function test_provider_exception_is_not_leaked(): void
    {
        $this->register('broken', ['product' => 'Product'], function (): array {
            throw new RuntimeException('SECRETSQL internal detail');
        });
        // A second, healthy provider still returns its results.
        $this->register('healthy', ['book' => 'Book'], fn (): array => [$this->hit('book', 1, 'Visible Hit', 9.0)]);

        $response = $this->get('/search?q=anything');
        $response->assertOk();
        $response->assertSee('Visible Hit');
        $response->assertDontSee('SECRETSQL', false);
        $response->assertSee('Search provider failed.');
    }

    public function test_no_open_redirect(): void
    {
        $this->register('ecommerce', ['product' => 'Product'], fn (): array => []);

        // A hostile redirect param must be ignored — the page renders, never bounces.
        $response = $this->get('/search?q=test&redirect=http://evil.example.com');
        $response->assertOk();
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_locale_is_forwarded_from_app(): void
    {
        app()->setLocale('vi');
        $this->register('ecommerce', ['product' => 'Product'], fn (SearchQuery $q): array => [
            $this->hit('product', 1, 'Locale: '.$q->locale, 5.0),
        ]);

        $this->get('/search?q=xx')
            ->assertOk()
            ->assertSee('Locale: vi');
    }
}
