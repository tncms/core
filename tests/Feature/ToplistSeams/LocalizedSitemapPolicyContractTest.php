<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate E (H-4): an owner may answer the batched
 * indexability question per locale. Core asks once per owner per chunk with
 * the chunk locale; the page asks with the page locale. Owners on the
 * original locale-blind contract behave exactly as before.
 */
final class LocalizedSitemapPolicyContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
        $this->sxPlugin('sx-owner');
    }

    /** @return list<string> locs of one child sitemap */
    private function chunk(string $name): array
    {
        app('cms.sitemap')->invalidateCore();
        preg_match_all('#<loc>([^<]+)</loc>#', (string) $this->get('/sitemaps/'.$name.'.xml')->assertOk()->streamedContent(), $m);

        return $m[1];
    }

    private function robots(Content $post, string $locale): string
    {
        $html = (string) $this->get($this->sxPath($post, $locale))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<meta name="robots" content="([^"]+)"/', $html, $m));

        return $m[1];
    }

    public function test_exclusion_applies_only_to_the_chunk_locale(): void
    {
        $policy = $this->sxProvider('sx-owner', 'LocalePolicy');
        $viOnly = $this->sxOwnedPost('sx-owner', 'Locale Excluded In Vi');
        $both = $this->sxOwnedPost('sx-owner', 'Locale Listed Both');
        $core = $this->sxCorePost('Locale Core Plain');
        $policy::$excludeByLocale = ['vi' => [(int) $viOnly->id]];

        $vi = $this->chunk('core-posts-vi-1');
        $en = $this->chunk('core-posts-en-1');

        $this->assertNotContains(url($this->sxPath($viOnly, 'vi')), $vi);
        $this->assertContains(url($this->sxPath($viOnly, 'en')), $en);
        $this->assertContains(url($this->sxPath($both, 'vi')), $vi);
        $this->assertContains(url($this->sxPath($both, 'en')), $en);
        $this->assertContains(url($this->sxPath($core, 'vi')), $vi);

        $chunkCalls = array_values(array_filter($policy::$calls, static fn (string $c): bool => str_starts_with($c, 'locale-policy:') && substr_count($c, ',') >= 1));
        $this->assertCount(2, $chunkCalls, 'one batched call per owner per chunk');
        $this->assertStringStartsWith('locale-policy:vi:', $chunkCalls[0]);
        $this->assertStringStartsWith('locale-policy:en:', $chunkCalls[1]);
        $this->assertSame([], array_filter($policy::$calls, static fn (string $c): bool => str_starts_with($c, 'policy:')), 'locale-blind method not used');
    }

    public function test_page_robots_follow_the_page_locale(): void
    {
        $policy = $this->sxProvider('sx-owner', 'LocalePolicy');
        $post = $this->sxOwnedPost('sx-owner', 'Locale Page Robots');
        $policy::$excludeByLocale = ['vi' => [(int) $post->id]];

        $this->assertStringStartsWith('noindex', $this->robots($post, 'vi'));
        $this->assertStringStartsWith('index', $this->robots($post, 'en'));
    }

    public function test_locale_blind_policy_is_unchanged(): void
    {
        $policy = $this->sxProvider('sx-owner', 'SeoOnly');
        $post = $this->sxOwnedPost('sx-owner', 'Locale Blind Excluded');
        $policy::$exclude = [(int) $post->id];

        $this->assertNotContains(url($this->sxPath($post, 'vi')), $this->chunk('core-posts-vi-1'));
        $this->assertNotContains(url($this->sxPath($post, 'en')), $this->chunk('core-posts-en-1'));
        $this->assertStringStartsWith('noindex', $this->robots($post, 'en'));
        $this->assertSame([], array_filter($policy::$calls, static fn (string $c): bool => str_starts_with($c, 'locale-policy:')));
    }

    public function test_unavailable_owned_content_stays_out_of_every_locale(): void
    {
        $policy = $this->sxProvider('sx-owner', 'LocalePolicy');
        $post = $this->sxOwnedPost('sx-owner', 'Locale Unavailable');
        $this->sxCorePost('Locale Keeps Chunk');
        $this->sxCode('sx-owner')->availability($post->id, \TheNguyen\CMS\Content\Extension\ContentAvailability::Unavailable);

        $this->assertNotContains(url($this->sxPath($post, 'vi')), $this->chunk('core-posts-vi-1'));
        $this->assertNotContains(url($this->sxPath($post, 'en')), $this->chunk('core-posts-en-1'));
        $this->assertSame([], $policy::$calls, 'hidden items never reach the policy');
    }
}
