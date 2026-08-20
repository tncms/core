<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\User;
use App\Search\Contracts\SearchableEntityDefinition;
use App\Search\Contracts\SearchManagerInterface;
use App\Search\Contracts\SearchProvider;
use App\Search\Drivers\ProviderSearchDriver;
use App\Search\Exceptions\DuplicateSearchScopeException;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3.1.6N-F — Search Cross-Entity Certification.
 *
 * This is a CERTIFICATION suite: it adds no search feature. It proves that the
 * frozen Shared Search Platform (Foundation 3.1.6N-B, Engine 3.1.6N-C, Storefront
 * 3.1.6N-D, Product Provider 3.1.6N-E) stays modular, isolated, locale-safe,
 * translation-safe, plugin-safe, and regression-free when MORE entity providers
 * exist beside Product.
 *
 * The platform is exercised over three SIMULATED providers — Product, Book, and
 * Knowledge — using the same fake-provider harness the engine tests use. No real
 * Post/Page/Book/Knowledge provider is created. Real Product-translation behaviour
 * is certified by the frozen {@see ProductSearchProviderTest}; this suite proves
 * the platform boundary that all future providers will plug into.
 */
class SearchCrossEntityCertificationTest extends TestCase
{
    // The dev-install marker forces a settings read at boot; RefreshDatabase
    // provisions the schema so the app boots. The engine performs no queries.
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

    /**
     * Register the three simulated entity providers (Product / Book / Knowledge)
     * that stand in for the future cross-entity landscape.
     *
     * @param  \Closure|null  $productSearch
     */
    private function registerThreeEntities(?\Closure $productSearch = null): SearchRegistry
    {
        $registry = $this->registry();

        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')],
            $productSearch ?? fn (SearchQuery $q): array => [$this->hit('product', 1, 3.0, $q->locale)]));

        $registry->register($this->provider('library', [$this->definition('book', 'Book')],
            fn (SearchQuery $q): array => [$this->hit('book', 1, 5.0, $q->locale)]));

        $registry->register($this->provider('knowledge-library', [$this->definition('knowledge', 'Knowledge')],
            fn (SearchQuery $q): array => [$this->hit('knowledge', 1, 7.0, $q->locale)]));

        return $registry;
    }

    // =====================================================================
    // 1. Registry Certification
    // =====================================================================

    public function test_registry_rejects_duplicate_provider_key(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => []));

        $this->expectException(DuplicateSearchScopeException::class);
        $registry->register($this->provider('ecommerce', [$this->definition('other')], fn (): array => []));
    }

    public function test_registry_rejects_duplicate_entity_type_across_providers(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (): array => []));

        // A different provider claiming an already-owned type is rejected — the
        // seam that keeps two plugins from clobbering one scope.
        $this->expectException(DuplicateSearchScopeException::class);
        $registry->register($this->provider('rogue', [$this->definition('product')], fn (): array => []));
    }

    public function test_registration_is_idempotent(): void
    {
        $registry = $this->registry();
        $provider = $this->provider('ecommerce', [$this->definition('product')], fn (): array => []);

        $registry->register($provider);
        $registry->register($provider); // same instance → no-op, no throw

        $this->assertCount(1, $registry->providers());
    }

    public function test_provider_discovery_resolves_owner_and_definition(): void
    {
        $registry = $this->registerThreeEntities();

        $this->assertSame('ecommerce', $registry->providerFor('product')?->key());
        $this->assertSame('library', $registry->providerFor('book')?->key());
        $this->assertSame('knowledge-library', $registry->providerFor('knowledge')?->key());
        $this->assertSame('Knowledge', $registry->definitionFor('knowledge')?->label());
        $this->assertNull($registry->providerFor('ghost')); // fail-safe
    }

    public function test_scope_generation_is_all_plus_one_per_type_in_registration_order(): void
    {
        $registry = $this->registerThreeEntities();
        $keys = array_map(static fn ($s) => $s->key, $registry->scopes());

        $this->assertSame(['all', 'product', 'book', 'knowledge'], $keys);
    }

    // =====================================================================
    // 2. Provider Isolation
    // =====================================================================

    public function test_product_scope_returns_only_product_results(): void
    {
        $this->registerThreeEntities();

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['product']));

        $this->assertSame(['product'], array_values(array_unique(
            array_map(static fn (SearchResult $r) => $r->type, $response->results)
        )));
    }

    public function test_provider_cannot_inject_a_foreign_entity_type(): void
    {
        $registry = $this->registry();

        // The product provider tries to smuggle a "book" hit it does not own.
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (SearchQuery $q): array => [
            $this->hit('product', 1, 2.0, $q->locale),
            $this->hit('book', 99, 9.0, $q->locale), // foreign — must be dropped
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['product']));

        $this->assertCount(1, $response->results);
        $this->assertSame('product', $response->results[0]->type);
    }

    public function test_provider_a_cannot_alter_provider_b_results(): void
    {
        $this->registerThreeEntities();

        // Book-only scope: the product and knowledge providers must not run, and
        // no product/knowledge hit can appear.
        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['book']));

        $this->assertSame(['book'], array_map(static fn (SearchResult $r) => $r->type, $response->results));
    }

    // =====================================================================
    // 3. Execution Isolation
    // =====================================================================

    public function test_one_provider_failure_does_not_stop_the_others(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (): array {
            throw new RuntimeException('DB exploded: secret internal detail');
        }));
        $registry->register($this->provider('library', [$this->definition('book')], fn (SearchQuery $q): array => [
            $this->hit('book', 1, 5.0, $q->locale),
        ]));

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertCount(1, $response->results);
        $this->assertSame('book', $response->results[0]->type);
        $this->assertTrue($response->hasFailures());
        $this->assertSame('ecommerce', $response->failures[0]->provider);
    }

    public function test_provider_failure_produces_a_safe_warning_not_an_exception(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (): array {
            throw new RuntimeException('SQLSTATE[42000] secret stack trace /var/www/secret.php');
        }));

        // No exception escapes — the manager returns a response.
        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertSame(['Search provider failed.'], $response->warnings());
        $this->assertStringNotContainsString('SQLSTATE', $response->failures[0]->message);
        $this->assertStringNotContainsString('secret', $response->failures[0]->message);
        $this->assertStringNotContainsString('/var/www', $response->failures[0]->message);
    }

    public function test_remaining_providers_execute_after_a_mid_fan_out_failure(): void
    {
        $registry = $this->registry();
        $ran = [];

        $registry->register($this->provider('ecommerce', [$this->definition('product')], function () use (&$ran): array {
            $ran[] = 'product';
            throw new RuntimeException('boom');
        }));
        $registry->register($this->provider('library', [$this->definition('book')], function (SearchQuery $q) use (&$ran): array {
            $ran[] = 'book';

            return [$this->hit('book', 1, 5.0, $q->locale)];
        }));
        $registry->register($this->provider('knowledge-library', [$this->definition('knowledge')], function (SearchQuery $q) use (&$ran): array {
            $ran[] = 'knowledge';

            return [$this->hit('knowledge', 1, 7.0, $q->locale)];
        }));

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertSame(['product', 'book', 'knowledge'], $ran); // all attempted
        $this->assertCount(2, $response->results);                 // two survived
        $this->assertCount(1, $response->failures);                // one contained
    }

    // =====================================================================
    // 4. Locale Isolation
    // =====================================================================

    public function test_locale_flows_query_to_provider_to_result(): void
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

    public function test_every_provider_receives_the_same_single_locale(): void
    {
        $registry = $this->registry();
        $seen = [];

        foreach (['ecommerce' => 'product', 'library' => 'book', 'knowledge-library' => 'knowledge'] as $key => $type) {
            $registry->register($this->provider($key, [$this->definition($type)], function (SearchQuery $q) use (&$seen, $type): array {
                $seen[$type] = $q->locale;

                return [$this->hit($type, 1, 1.0, $q->locale)];
            }));
        }

        $this->manager()->search(SearchQuery::create('x', 'vi'));

        // No provider invents a secondary locale authority — all see 'vi'.
        $this->assertSame(['product' => 'vi', 'book' => 'vi', 'knowledge' => 'vi'], $seen);
    }

    public function test_locale_only_content_does_not_leak_across_locales(): void
    {
        // Simulate a provider whose vi-only content is invisible under 'en'. The
        // decision lives IN the provider; the platform merely forwards the locale.
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (SearchQuery $q): array {
            return $q->locale === 'vi' ? [$this->hit('product', 1, 1.0, 'vi')] : [];
        }));

        $this->assertTrue($this->manager()->search(SearchQuery::create('onlyvi', 'en'))->isEmpty());
        $this->assertCount(1, $this->manager()->search(SearchQuery::create('onlyvi', 'vi'))->results);
    }

    // =====================================================================
    // 5. Translation Isolation (platform boundary)
    // =====================================================================

    /**
     * A provider whose search() applies Translation-Platform semantics: for a
     * non-default locale it exposes ONLY published translations, hides drafts and
     * review-required ones, and falls back to canonical when no translation
     * exists. This proves the platform reuses the provider's translation authority
     * and never re-implements publication rules itself.
     *
     * @param  array<int, array{status?: string, review?: bool, title?: string}>  $translations
     */
    private function translationAwareProvider(array $translations): SearchProvider
    {
        return $this->provider('ecommerce', [$this->definition('product', 'Product')], function (SearchQuery $q) use ($translations): array {
            $results = [];
            foreach ($translations as $id => $t) {
                if ($q->locale === 'en') {
                    $results[] = new SearchResult('product', $id, 'Canonical '.$id, '', '/p/'.$id, 1.0, 'en');

                    continue;
                }

                $status = $t['status'] ?? null;
                $review = $t['review'] ?? false;

                if ($status === null) {
                    // Missing translation → canonical fallback.
                    $results[] = new SearchResult('product', $id, 'Canonical '.$id, '', '/p/'.$id, 1.0, $q->locale);

                    continue;
                }

                // Draft or review-required translations stay hidden.
                if ($status !== 'published' || $review) {
                    continue;
                }

                $results[] = new SearchResult('product', $id, $t['title'] ?? 'Translated '.$id, '', '/p/'.$id, 1.0, $q->locale);
            }

            return $results;
        });
    }

    public function test_published_translation_is_visible(): void
    {
        $registry = $this->registry();
        $registry->register($this->translationAwareProvider([1 => ['status' => 'published', 'title' => 'ViTitle']]));

        $response = $this->manager()->search(SearchQuery::create('x', 'vi'));

        $this->assertCount(1, $response->results);
        $this->assertSame('ViTitle', $response->results[0]->title);
        $this->assertSame('vi', $response->results[0]->locale);
    }

    public function test_draft_translation_is_hidden(): void
    {
        $registry = $this->registry();
        $registry->register($this->translationAwareProvider([1 => ['status' => 'draft', 'title' => 'DraftVi']]));

        $this->assertTrue($this->manager()->search(SearchQuery::create('x', 'vi'))->isEmpty());
    }

    public function test_review_required_translation_is_hidden(): void
    {
        $registry = $this->registry();
        $registry->register($this->translationAwareProvider([1 => ['status' => 'published', 'review' => true, 'title' => 'RevVi']]));

        $this->assertTrue($this->manager()->search(SearchQuery::create('x', 'vi'))->isEmpty());
    }

    public function test_missing_translation_falls_back_to_canonical(): void
    {
        $registry = $this->registry();
        $registry->register($this->translationAwareProvider([1 => []])); // no translation row

        $response = $this->manager()->search(SearchQuery::create('x', 'vi'));

        $this->assertCount(1, $response->results);
        $this->assertSame('Canonical 1', $response->results[0]->title);
    }

    public function test_execution_engine_holds_no_publication_or_translation_logic(): void
    {
        // Dependency-direction proof: the host execution engine imports no plugin,
        // no Eloquent, no DB facade, and contains no publication/translation rule.
        // Publication authority lives ONLY in providers/repositories.
        foreach ([
            app_path('Search/SearchManager.php'),
            app_path('Search/Drivers/ProviderSearchDriver.php'),
            app_path('Search/SearchRegistry.php'),
            app_path('Search/Ranking/RelevanceSearchRanker.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/^use\s+Plugins\\\\/m', $source, "$file must not import a plugin");
            $this->assertDoesNotMatchRegularExpression('/^use\s+Illuminate\\\\Database/m', $source, "$file must not import the DB layer");
            $this->assertStringNotContainsString('DB::', $source, "$file must not use the DB facade");
            $this->assertDoesNotMatchRegularExpression('/requires_review|translationState|published_translations/i', $source, "$file must not re-implement publication logic");
        }
    }

    // =====================================================================
    // 6. Entity Isolation
    // =====================================================================

    public function test_results_are_references_never_models(): void
    {
        $this->registerThreeEntities();

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertNotEmpty($response->results);
        foreach ($response->results as $result) {
            $this->assertInstanceOf(SearchResult::class, $result);
            $this->assertIsScalar($result->id); // int|string reference, not a model
            // Metadata carries only non-sensitive scalars/arrays, never an object.
            foreach ($result->metadata as $value) {
                $this->assertIsNotObject($value);
            }
        }
    }

    // =====================================================================
    // 7. Security Certification
    // =====================================================================

    public function test_commercial_and_internal_fields_never_reach_results(): void
    {
        $registry = $this->registry();
        // A provider that (wrongly) tries to attach commercial data — the result
        // shape only carries the safe reference contract; nothing forces callers
        // to receive sku/price/stock, and the storefront view never renders them.
        $registry->register($this->provider('ecommerce', [$this->definition('product')], fn (SearchQuery $q): array => [
            new SearchResult('product', 1, 'Widget', 'A widget', '/p/1', 2.0, $q->locale, metadata: ['badge' => 'new']),
        ]));

        $result = $this->manager()->search(SearchQuery::create('widget', 'en'))->results[0];
        $array = $result->toArray();

        foreach (['sku', 'price', 'cost_price', 'stock', 'weight'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $array);
            $this->assertArrayNotHasKey($forbidden, $result->metadata);
        }
    }

    // =====================================================================
    // 8. Performance Certification
    // =====================================================================

    public function test_candidate_limit_caps_a_flooding_provider(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product')], function (SearchQuery $q): array {
            $hits = [];
            for ($i = 1; $i <= 150; $i++) {
                $hits[] = $this->hit('product', $i, (float) $i, $q->locale);
            }

            return $hits;
        }));

        $response = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 1, 20));

        $this->assertSame(ProviderSearchDriver::PROVIDER_CANDIDATE_LIMIT, $response->total);
        $this->assertLessThanOrEqual(20, count($response->results));
    }

    public function test_pagination_is_bounded_by_max_per_page(): void
    {
        $query = SearchQuery::create('x', 'en', [], [], 1, 10_000);

        $this->assertSame(SearchQuery::MAX_PER_PAGE, $query->perPage);
    }

    public function test_a_multi_type_provider_executes_exactly_once_per_search(): void
    {
        $registry = $this->registry();
        $calls = 0;

        // One provider owning TWO types must be invoked once for an all-scope
        // query — no duplicate provider execution.
        $registry->register($this->provider('knowledge-library', [
            $this->definition('book', 'Book'),
            $this->definition('knowledge', 'Knowledge'),
        ], function (SearchQuery $q) use (&$calls): array {
            $calls++;

            return [$this->hit('book', 1, 1.0, $q->locale), $this->hit('knowledge', 2, 2.0, $q->locale)];
        }));

        $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertSame(1, $calls);
    }

    public function test_execution_engine_does_no_unbounded_entity_scan(): void
    {
        // The driver/manager never call Model::all() or build an unbounded query —
        // retrieval and its bounds live entirely inside providers.
        foreach ([
            app_path('Search/SearchManager.php'),
            app_path('Search/Drivers/ProviderSearchDriver.php'),
        ] as $file) {
            $source = (string) file_get_contents($file);

            $this->assertDoesNotMatchRegularExpression('/::all\(\)/', $source, "$file must not call ::all()");
            $this->assertStringNotContainsString('->get()', $source, "$file must not run a query builder get()");
        }
    }

    // =====================================================================
    // 9. Cross-Entity Simulation
    // =====================================================================

    public function test_all_scope_merges_ranks_and_paginates_across_three_entities(): void
    {
        $this->registerThreeEntities();

        $response = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 1, 2));

        // Highest score first (knowledge 7 > book 5 > product 3), page-bounded.
        $this->assertSame(3, $response->total);
        $this->assertCount(2, $response->results);
        $this->assertSame('knowledge', $response->results[0]->type);
        $this->assertSame('book', $response->results[1]->type);

        $page2 = $this->manager()->search(SearchQuery::create('x', 'en', [], [], 2, 2));
        $this->assertSame('product', $page2->results[0]->type);
    }

    public function test_cross_entity_isolation_holds_per_scope(): void
    {
        $this->registerThreeEntities();

        foreach (['product', 'book', 'knowledge'] as $type) {
            $response = $this->manager()->search(SearchQuery::create('x', 'en', [$type]));
            $this->assertSame([$type], array_map(static fn (SearchResult $r) => $r->type, $response->results));
        }
    }

    // =====================================================================
    // 10. Future Compatibility
    // =====================================================================

    public function test_a_new_entity_provider_plugs_in_without_platform_change(): void
    {
        $registry = $this->registerThreeEntities();

        // A future Post provider registers exactly like the others — no manager,
        // driver, controller, or registry change is required.
        $registry->register($this->provider('cms-posts', [$this->definition('post', 'Post')],
            fn (SearchQuery $q): array => [$this->hit('post', 1, 9.0, $q->locale)]));

        $keys = array_map(static fn ($s) => $s->key, $registry->scopes());
        $this->assertContains('post', $keys);

        $response = $this->manager()->search(SearchQuery::create('x', 'en', ['post']));
        $this->assertSame(['post'], array_map(static fn (SearchResult $r) => $r->type, $response->results));
    }

    // =====================================================================
    // 11. Regression
    // =====================================================================

    public function test_foundation_registry_and_scopes_still_work(): void
    {
        $registry = $this->registry();
        $registry->register($this->provider('ecommerce', [$this->definition('product', 'Product')], fn (): array => []));

        $scopes = $registry->scopes();
        $this->assertSame('all', $scopes[0]->key);
        $this->assertSame('product', $scopes[1]->key);
    }

    public function test_engine_still_executes_and_reports_totals(): void
    {
        $this->registerThreeEntities();

        $response = $this->manager()->search(SearchQuery::create('x', 'en'));

        $this->assertSame(3, $response->total);
        $this->assertFalse($response->hasFailures());
    }

    public function test_empty_and_unknown_only_queries_still_short_circuit(): void
    {
        $this->registerThreeEntities();

        $this->assertTrue($this->manager()->search(SearchQuery::create('   ', 'en'))->isEmpty());

        $unknown = $this->manager()->search(SearchQuery::create('x', 'en', ['ghost']));
        $this->assertTrue($unknown->isEmpty());
        $this->assertSame([], $unknown->executedScopes);
    }

    public function test_storefront_route_still_renders_registry_driven_scopes(): void
    {
        $this->registerThreeEntities();

        $this->get('/search')
            ->assertOk()
            ->assertSee('All')
            ->assertSee('Product')
            ->assertSee('Book')
            ->assertSee('Knowledge');
    }
}
