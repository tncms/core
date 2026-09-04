<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Search\SearchRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * CORE-FRONTEND-1 — search template hierarchy (theme override -> Core fallback).
 *
 * The Core-owned fallback (frontend.search.index) is exercised by the wider
 * search suite; this proves the theme specialized template wins when present.
 */
class SearchThemeTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SearchRegistry::class)->flush();
    }

    public function test_theme_search_template_overrides_core_fallback(): void
    {
        View::replaceNamespace('theme', [base_path('tests/Fixtures/search-theme')]);
        $this->assertTrue(View::exists('theme::search.index'));

        $this->get('/search?q=hello')
            ->assertOk()
            ->assertSee('theme-search-override', false)
            ->assertSee('theme override for "hello"', false)
            ->assertDontSee('tn-search-heading', false);
    }

    public function test_core_fallback_used_when_theme_has_no_search_template(): void
    {
        // Default theme ships no search/index -> Core fallback renders in the layout.
        $this->assertFalse(View::exists('theme::search.index'));

        $this->get('/search')
            ->assertOk()
            ->assertSee('tn-search-heading', false);
    }
}
