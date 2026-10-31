<?php

declare(strict_types=1);

namespace Tests\Feature\ToplistSeams;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Feature\ToplistSeams\Concerns\SeamFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Content\Extension\OwnerState;

/**
 * CORE-38-TOPLIST-SEAMS-R1 Gate D3 (H-3c): a plugin that is configured active
 * but whose runtime could not be loaded this request is not an available
 * owner. Its content follows the stored fallback policy (page, discovery,
 * sitemap, markers) exactly like a missing owner; ordinary Core content and
 * healthy owners are unaffected.
 */
final class OwnerLoadabilityContractTest extends TestCase
{
    use RefreshDatabase;
    use SeamFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSeamSite();
    }

    /** Boot the fixture root's active plugins the way a real request does. */
    private function boot(): void
    {
        app('cms.extension')->flushRegistry();
        app('cms.extension')->bootActivePlugins();
    }

    /** @return array{0: \TheNguyen\CMS\Models\Content, 1: \TheNguyen\CMS\Models\Content, 2: \TheNguyen\CMS\Models\Content} */
    private function fixtures(string $broken): array
    {
        $hidden = $this->sxCorePost('Broken Hidden '.$broken);
        $this->sxMarkOwned($hidden, $broken, ContentFallbackPolicy::Hide404);
        $adopted = $this->sxCorePost('Broken Adopted '.$broken);
        $this->sxMarkOwned($adopted, $broken, ContentFallbackPolicy::CoreRender);
        $core = $this->sxCorePost('Plain Core '.$broken);

        return [$hidden, $adopted, $core];
    }

    /** @return list<string> */
    public static function brokenRuntimes(): array
    {
        return [['missing provider class'], ['provider throws on register']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brokenRuntimes')]
    public function test_configured_active_but_unloadable_owner_applies_its_fallback(string $case): void
    {
        $slug = $case === 'missing provider class' ? 'sx-missing' : 'sx-throwing';
        $this->sxPlugin($slug, [$case === 'missing provider class'
            ? $this->sxRuntimeNamespace($slug).'\\DeletedServiceProvider'
            : $this->sxRuntimeNamespace($slug).'\\ThrowingServiceProvider']);
        [$hidden, $adopted, $core] = $this->fixtures($slug);

        Log::spy();
        $this->boot();

        $missing = $case === 'missing provider class';
        Log::shouldHaveReceived($missing ? 'warning' : 'error')
            ->withArgs(static fn ($message) => str_contains((string) $message, $missing ? 'provider class missing' : 'failed to register plugin'))->once();
        $this->assertTrue(app('cms.extension')->isPluginActive($slug), 'still configured active');
        $this->assertTrue(app('cms.extension')->isPluginUnloadable($slug));
        $this->assertSame(OwnerState::Missing, app('cms.content_ownership')->ownerState($slug));
        $this->assertNotContains($slug, app('cms.content_ownership')->activeOwnerSlugs());
        $this->assertSame(OwnerState::Missing, app('cms.content_ownership')->markerFor($hidden->id)?->state);

        $this->get($this->sxPath($hidden))->assertNotFound();
        $this->get($this->sxPath($adopted))->assertOk()->assertSee('<title>Broken Adopted '.$slug.'</title>', false);
        $this->get($this->sxPath($core))->assertOk();

        $listed = \TheNguyen\CMS\Models\Content::query()->published()->discoverable('vi')->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $hidden->id, $listed);
        $this->assertContains((int) $adopted->id, $listed);
        $this->assertContains((int) $core->id, $listed);

        $locs = $this->sitemapLocs();
        $this->assertNotContains(url($this->sxPath($hidden)), $locs);
        $this->assertContains(url($this->sxPath($adopted)), $locs);
        $this->assertContains(url($this->sxPath($core)), $locs);
    }

    public function test_deactivating_an_unloadable_owner_makes_it_inactive_again(): void
    {
        $this->sxPlugin('sx-flaky', [$this->sxRuntimeNamespace('sx-flaky').'\DeletedServiceProvider']);
        $this->boot();
        $this->assertSame(OwnerState::Missing, app('cms.content_ownership')->ownerState('sx-flaky'));

        app('cms.extension')->deactivatePlugin('sx-flaky');
        app('cms.extension')->flushRegistry();

        $this->assertFalse(app('cms.extension')->isPluginUnloadable('sx-flaky'));
        $this->assertSame(OwnerState::Inactive, app('cms.content_ownership')->ownerState('sx-flaky'));
    }

    public function test_healthy_owner_with_a_loadable_runtime_stays_active(): void
    {
        $this->sxPlugin('sx-healthy', [$this->sxRuntimeNamespace('sx-healthy').'\\HealthyServiceProvider']);
        $post = $this->sxOwnedPost('sx-healthy', 'Healthy Owned');

        $this->boot();

        $this->assertSame(OwnerState::Active, app('cms.content_ownership')->ownerState('sx-healthy'));
        $this->get($this->sxPath($post))->assertOk();
    }

    public function test_a_plugin_without_declared_providers_is_loadable(): void
    {
        $this->sxPlugin('sx-bare');
        $post = $this->sxOwnedPost('sx-bare', 'Bare Owned');

        $this->boot();

        $this->assertSame(OwnerState::Active, app('cms.content_ownership')->ownerState('sx-bare'));
        $this->get($this->sxPath($post))->assertOk();
    }

    /** @return list<string> every loc across the sitemap index */
    private function sitemapLocs(): array
    {
        app('cms.sitemap')->invalidateCore();
        $index = (string) $this->get('/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>([^<]+)</loc>#', $index, $children);
        $locs = [];
        foreach ($children[1] as $child) {
            preg_match_all('#<loc>([^<]+)</loc>#', (string) $this->get((string) parse_url($child, PHP_URL_PATH))->streamedContent(), $m);
            array_push($locs, ...$m[1]);
        }

        return $locs;
    }
}
