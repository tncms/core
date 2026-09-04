<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use TheNguyen\CMS\Services\SeoManager;

/**
 * CORE-FRONTEND-1 — search page SEO policy.
 *
 * The frontend search page is a utility page: it must advertise
 * robots=noindex,follow so query/result permutations are never indexed while
 * links stay followable. The site-wide "discourage search engines" toggle still
 * wins, and normal content SEO is untouched.
 */
class SearchSeoNoindexTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_page_is_noindex_follow(): void
    {
        $this->get('/search')
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('content="noindex,follow"', false);
    }

    public function test_search_page_with_query_is_still_noindex(): void
    {
        $this->get('/search?q=anything')
            ->assertOk()
            ->assertSee('content="noindex,follow"', false);
    }

    public function test_noindex_seam_returns_noindex_follow(): void
    {
        $seo = app(SeoManager::class)->reset()->noindex();

        $this->assertSame('noindex,follow', $seo->robots());
    }

    public function test_noindex_nofollow_variant(): void
    {
        $seo = app(SeoManager::class)->reset()->noindex(false);

        $this->assertSame('noindex,nofollow', $seo->robots());
    }

    public function test_site_wide_discourage_still_wins_over_noindex_follow(): void
    {
        app('cms.settings')->set('seo.noindex_site', true);

        $seo = app(SeoManager::class)->reset()->noindex(); // asks for noindex,follow

        // Site-wide discourage forces nofollow — it must win over the override.
        $this->assertSame('noindex,nofollow', $seo->robots());
    }

    public function test_normal_context_is_unaffected(): void
    {
        $this->assertSame('index,follow', app(SeoManager::class)->reset()->robots());
    }
}
