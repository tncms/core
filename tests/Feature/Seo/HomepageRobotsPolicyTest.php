<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sitemap\Concerns\SitemapFixtures;
use Tests\Support\ContentFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-SEO-RELEASE-HARDENING-R1, Track H2: the homepage uses the per-item SEO
 * policy of the Page rendered at `/`, combined with the site-wide policy; the
 * more restrictive rule wins. The home sitemap entry follows the same
 * effective decision. Section-layout and latest-posts homes render no single
 * page, so they keep the site policy.
 */
final class HomepageRobotsPolicyTest extends TestCase
{
    use RefreshDatabase;
    use SitemapFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSitemapSite(english: true);
    }

    private function fresh(): void
    {
        app('cms.localization.current_resource')->clear();
        app('cms.seo')->reset();
    }

    /** @return array{robots: list<string>, canonical: list<string>} */
    private function homeHead(string $path): array
    {
        $this->fresh();
        $response = $this->get($path);
        $response->assertOk();
        $html = (string) $response->getContent();
        preg_match_all('/<meta\s+name="robots"\s+content="([^"]*)"/i', $html, $r);
        preg_match_all('/<link rel="canonical" href="([^"]*)"/', $html, $c);

        return ['robots' => $r[1], 'canonical' => $c[1]];
    }

    /** @return list<string> */
    private function locs(): array
    {
        $this->fresh();

        return $this->allLocs();
    }

    private function setPolicy(Content $page, ?string $policy): void
    {
        app('cms.content')->update($page->fresh(), ['seo_robots' => $policy], null, ContentFixtures::context());
    }

    private function homeLocs(): array
    {
        return ['http://localhost', 'http://localhost/en'];
    }

    public function test_the_conventional_home_page_policy_applies_at_the_root_in_every_locale(): void
    {
        $home = $this->smPost('Trang chu', english: true, type: 'page');

        foreach (['/', '/en'] as $path) {
            $this->assertSame(['index,follow'], $this->homeHead($path)['robots'], 'default follows the site policy: '.$path);
        }
        $this->assertSame([], array_diff($this->homeLocs(), $this->locs()), 'home listed by default');

        $this->setPolicy($home, 'noindex');
        foreach (['/' => 'http://localhost', '/en' => 'http://localhost/en'] as $path => $canonical) {
            $head = $this->homeHead($path);
            $this->assertSame(['noindex,follow'], $head['robots'], 'exactly one tag: '.$path);
            $this->assertSame([$canonical], $head['canonical'], 'canonical unchanged: '.$path);
        }
        $locs = $this->locs();
        foreach ($this->homeLocs() as $loc) {
            $this->assertNotContains($loc, $locs, 'a noindex home leaves the sitemap without a manual flush');
        }

        $this->setPolicy($home, null);
        $this->assertSame(['index,follow'], $this->homeHead('/')['robots']);
        $this->assertSame([], array_diff($this->homeLocs(), $this->locs()), 'reset restores the home entry');
    }

    public function test_nofollow_and_both_restrictions_are_applied_at_the_root(): void
    {
        $home = $this->smPost('Trang chu', english: true, type: 'page');

        $this->setPolicy($home, 'nofollow');
        $this->assertSame(['index,nofollow'], $this->homeHead('/')['robots']);
        $this->assertSame([], array_diff($this->homeLocs(), $this->locs()), 'nofollow stays listed');

        $this->setPolicy($home, 'noindex,nofollow');
        $this->assertSame(['noindex,nofollow'], $this->homeHead('/en')['robots']);
        $this->assertNotContains('http://localhost', $this->locs());
    }

    public function test_site_wide_noindex_wins_over_a_default_home_page(): void
    {
        $this->smPost('Trang chu', english: true, type: 'page');
        settings()->set('seo.noindex_site', true, 'boolean');

        $this->assertSame(['noindex,nofollow'], $this->homeHead('/')['robots']);
        $this->fresh();
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_the_static_front_page_policy_follows_reassignment(): void
    {
        $this->smPost('Trang chu', english: false, type: 'page');
        $hidden = $this->smPost('Gioi thieu', english: true, type: 'page', extra: ['seo_robots' => 'noindex']);
        $shown = $this->smPost('Lien he', english: true, type: 'page');
        settings()->set('reading.homepage_display', 'static_page', 'string');
        settings()->set('reading.homepage_page_id', $hidden->id, 'integer');

        $this->assertSame(['noindex,follow'], $this->homeHead('/')['robots']);
        $this->assertSame(['noindex,follow'], $this->homeHead('/en')['robots']);
        $this->assertNotContains('http://localhost', $this->locs());

        settings()->set('reading.homepage_page_id', $shown->id, 'integer');
        $this->assertSame(['index,follow'], $this->homeHead('/')['robots'], 'the new front page decides');
        $this->assertSame([], array_diff($this->homeLocs(), $this->locs()), 'reassignment invalidates the home entry');

        // The former front page keeps its own policy at its own URL.
        $this->assertSame(['noindex,follow'], $this->homeHead((string) parse_url(content_url($hidden, 'vi'), PHP_URL_PATH))['robots']);
    }

    public function test_an_unpublished_front_page_falls_back_with_the_fallback_policy(): void
    {
        $hidden = $this->smPost('Gioi thieu', english: false, type: 'page', extra: ['seo_robots' => 'noindex']);
        settings()->set('reading.homepage_display', 'static_page', 'string');
        settings()->set('reading.homepage_page_id', $hidden->id, 'integer');
        app('cms.content')->changeStatus($hidden->fresh(), 'draft', null, ContentFixtures::context());

        $this->assertSame(['index,follow'], $this->homeHead('/')['robots'], 'the draft is not rendered, so its policy does not apply');
        $this->assertContains('http://localhost', $this->locs());
    }

    public function test_a_latest_posts_home_keeps_the_site_policy(): void
    {
        $page = $this->smPost('Gioi thieu', english: false, type: 'page', extra: ['seo_robots' => 'noindex']);
        $this->smPost('Bai moi', english: false);
        settings()->set('reading.homepage_display', 'latest_posts', 'string');
        settings()->set('reading.homepage_page_id', $page->id, 'integer');

        $this->assertSame(['index,follow'], $this->homeHead('/')['robots']);
        $this->assertContains('http://localhost', $this->locs());
    }

    public function test_the_old_home_slug_still_redirects_when_the_home_is_noindex(): void
    {
        $home = $this->smPost('Trang chu', english: true, type: 'page', extra: ['seo_robots' => 'noindex']);
        $this->fresh();
        $this->get('/trang-chu')->assertStatus(301)->assertHeader('Location', '/');
        $this->fresh();
        $this->get('/en/'.$home->translations()->where('locale', 'en')->value('slug'))->assertStatus(301)->assertHeader('Location', '/en');
    }
}
