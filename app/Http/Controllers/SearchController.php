<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Search\Contracts\SearchManagerInterface;
use App\Search\Presentation\SearchPageViewModel;
use App\Search\SearchQuery;
use App\Search\SearchRegistry;
use App\Search\SearchScope;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * Public storefront search page (Phase 3.1.6N-D).
 *
 * The controller is transport only: sanitize input → build a {@see SearchQuery}
 * → call the {@see SearchManagerInterface} → hand a view model to Blade. It never
 * queries a model, knows any Product/Post/Book detail, ranks, or redirects — all
 * search logic lives behind the frozen execution engine (3.1.6N-C), and scopes
 * come from the registry (3.1.6N-B), so new plugin search types appear
 * automatically with no controller change.
 */
final class SearchController extends Controller
{
    private const MIN_KEYWORD = 2;

    private const MAX_KEYWORD = 128;

    private const PER_PAGE = 12;

    private const MAX_PAGE = 10_000;

    public function __construct(
        private readonly SearchManagerInterface $manager,
        private readonly SearchRegistry $registry,
    ) {}

    public function index(Request $request): ViewContract
    {
        // Sanitize (never redirect) so a hostile query string cannot drive a
        // validation bounce. Keyword is trimmed and length-bounded; page is a
        // safe positive integer; scope is a plain string the registry validates.
        $keyword = trim((string) $request->query('q', ''));
        if (mb_strlen($keyword) > self::MAX_KEYWORD) {
            $keyword = mb_substr($keyword, 0, self::MAX_KEYWORD);
        }

        $selectedScope = trim((string) $request->query('scope', SearchScope::ALL));
        if ($selectedScope === '') {
            $selectedScope = SearchScope::ALL;
        }

        $page = max(1, min(self::MAX_PAGE, (int) $request->query('page', 1)));

        $scopes = $this->registry->scopes();

        /** @var array<string, string> $labels */
        $labels = [];
        foreach ($this->registry->definitions() as $type => $definition) {
            $labels[$type] = $definition->label();
        }

        $tooShort = $keyword !== '' && mb_strlen($keyword) < self::MIN_KEYWORD;

        $response = null;
        if ($keyword !== '' && ! $tooShort) {
            // ALL selects every scope (empty types); any other value is passed as
            // a single requested type — the registry silently drops it if unknown.
            $types = $selectedScope === SearchScope::ALL ? [] : [$selectedScope];

            $response = $this->manager->search(SearchQuery::create(
                keyword: $keyword,
                locale: app()->getLocale(),
                types: $types,
                page: $page,
                perPage: self::PER_PAGE,
            ));
        }

        $viewModel = SearchPageViewModel::build(
            keyword: $keyword,
            selectedScope: $selectedScope,
            scopes: $scopes,
            response: $response,
            labels: $labels,
            minLength: self::MIN_KEYWORD,
            tooShort: $tooShort,
        );

        // CORE-FRONTEND-1: search is a utility page — never indexed (noindex,
        // follow) so result/query permutations stay out of the index while links
        // remain followable. The site-wide "discourage search engines" SEO toggle
        // still wins. A neutral "Search" document title replaces the site default.
        seo()->forCustom(__('Search'), '')->noindex();

        // Theme override → host fallback. A theme may ship its own
        // "theme::search.index"; otherwise the platform view renders in the
        // active theme's layout. Themes are never edited by this phase.
        $view = View::exists('theme::search.index') ? 'theme::search.index' : 'frontend.search.index';

        return view($view, ['page' => $viewModel]);
    }
}
