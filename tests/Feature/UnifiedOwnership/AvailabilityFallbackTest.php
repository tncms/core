<?php

declare(strict_types=1);

namespace Tests\Feature\UnifiedOwnership;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\UnifiedOwnership\Concerns\UnifiedOwnershipFixtures;
use Tests\TestCase;
use TheNguyen\CMS\Content\Extension\ContentAvailability;
use TheNguyen\CMS\Content\Extension\PreparedContent;
use TheNguyen\CMS\Models\Content;

/**
 * CORE-UNIFIED-OWNERSHIP-R1 Gate H: availability, HTTP status and fallback
 * all read the single owner (managed_by); defaults are hide_404 for created
 * and core_render for adopted content; core_render is honoured only when
 * Core has a body to render; page, discovery and sitemap agree.
 */
final class AvailabilityFallbackTest extends TestCase
{
    use RefreshDatabase;
    use UnifiedOwnershipFixtures;

    private Content $created;

    private Content $adopted;

    private Content $core;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSite();
        $this->useAuthorityFixturePlugins();
        $this->guardedPlugin('uo-alpha');
        $owner = $this->ownerCode('uo-alpha');

        $this->core = $this->coreArticle('Core Article', null, ['slug' => 'core-article']);
        $this->actingAs($this->adopter(['posts.publish', 'posts.create']));
        $this->created = $owner->own(['type' => 'post', 'status' => 'published', 'locale' => 'en', 'title' => 'Created', 'slug' => 'created', 'content' => '<p>c</p>']);
        $adopt = $this->coreArticle('Adopted', null, ['slug' => 'adopted']);
        $this->adopted = $owner->claim($adopt, $adopt->revision);
        auth()->logout();
    }

    private function httpStatus(Content $content, string $locale = 'en'): int
    {
        return $this->get(parse_url(content_url($content->fresh('translations'), $locale), PHP_URL_PATH))->status();
    }

    /** @return list<int> */
    private function discoverable(): array
    {
        return Content::query()->published()->discoverable()->orderBy('id')->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    private function inSitemap(Content $content, string $locale = 'en'): bool
    {
        return in_array(url(content_url($content->fresh('translations'), $locale)), array_column(app('cms.seo')->sitemap(), 'loc'), true);
    }

    private function setOwnerActive(bool $active): void
    {
        $active ? app('cms.extension')->activatePlugin('uo-alpha') : app('cms.extension')->deactivatePlugin('uo-alpha');
        app('cms.extension')->flushRegistry();
    }

    public function test_active_inactive_removed_and_reactivated_owner(): void
    {
        $all = [$this->core->id, $this->created->id, $this->adopted->id];
        $this->assertSame([200, 200, 200], [$this->httpStatus($this->core), $this->httpStatus($this->created), $this->httpStatus($this->adopted)]);
        $this->assertSame($all, $this->discoverable());

        $this->setOwnerActive(false);
        $this->assertSame([200, 404, 200], [$this->httpStatus($this->core), $this->httpStatus($this->created), $this->httpStatus($this->adopted)], 'created: hide_404, adopted: core_render');
        $this->assertSame([$this->core->id, $this->adopted->id], $this->discoverable());
        $this->assertFalse($this->inSitemap($this->created));
        $this->assertTrue($this->inSitemap($this->adopted));
        $this->assertSame('uo-alpha', $this->created->refresh()->managed_by, 'ownership survives deactivation');

        File::moveDirectory($this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'uo-alpha', $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'uo-alpha.away');
        app('cms.extension')->flushRegistry();
        $this->assertSame([404, 200], [$this->httpStatus($this->created), $this->httpStatus($this->adopted)], 'missing owner: same fallbacks');
        $this->assertSame('uo-alpha', $this->adopted->refresh()->managed_by, 'ownership survives temporary removal');

        File::moveDirectory($this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'uo-alpha.away', $this->authorityFixtureRoot().DIRECTORY_SEPARATOR.'uo-alpha');
        $this->setOwnerActive(true);
        $this->assertSame([200, 200], [$this->httpStatus($this->created), $this->httpStatus($this->adopted)], 'reactivation restores both');
        $this->assertSame($all, $this->discoverable());
    }

    public function test_persisted_availability_states_map_to_http_status(): void
    {
        $this->actingAs($this->superAdmin());
        $owner = $this->ownerCode('uo-alpha');

        $owner->availability($this->created->id, ContentAvailability::Unavailable);
        $this->assertSame(404, $this->httpStatus($this->created));
        $owner->availability($this->created->id, ContentAvailability::Gone);
        $this->assertSame(404, $this->httpStatus($this->created), '410 is opt-in');
        settings()->set('content.allow_gone', true, 'boolean');
        $this->assertSame(410, $this->httpStatus($this->created));
        $owner->availability($this->created->id, ContentAvailability::TemporarilyUnavailable, 120);
        $response = $this->get(parse_url(content_url($this->created->fresh('translations'), 'en'), PHP_URL_PATH))->assertStatus(503);
        $this->assertSame('120', $response->headers->get('Retry-After'));
        $this->assertNotContains($this->created->id, $this->discoverable());

        $owner->availability($this->created->id, ContentAvailability::Available);
        $response = $this->get(parse_url(content_url($this->created->fresh('translations'), 'en'), PHP_URL_PATH))->assertOk();
        $directives = static fn (string $v): array => collect(explode(',', $v))->map(static fn ($d) => trim($d))->sort()->values()->all();
        $this->assertSame($directives(PreparedContent::MANAGED_CACHE_CONTROL), $directives((string) $response->headers->get('Cache-Control')));

        // A plugin cannot set the state of content it does not own.
        $this->assertSame('ownership:not_owner', $this->denial(fn () => $owner->availability($this->core->id, ContentAvailability::Unavailable)));
    }

    public function test_core_render_fails_closed_when_core_has_no_body_to_render(): void
    {
        // Adopted content whose en body is empty (the real body lived in the plugin).
        DB::table('cms_content_translations')->where('content_id', $this->adopted->id)->where('locale', 'en')->update(['content' => '']);
        $this->setOwnerActive(false);

        $this->assertSame(404, $this->httpStatus($this->adopted), 'no Core body: fail closed, no plugin code runs');
        $this->assertNotContains($this->adopted->id, $this->discoverable());
        $this->assertFalse($this->inSitemap($this->adopted));

        $this->setOwnerActive(true);
        $this->assertSame(200, $this->httpStatus($this->adopted), 'the active owner renders it');
    }

    public function test_core_render_is_per_locale(): void
    {
        $this->actingAs($this->superAdmin());
        $this->ownerCode('uo-alpha')->update($this->adopted, ['locale' => 'vi', 'title' => 'Da nhan', 'slug' => 'da-nhan', 'content' => '<p>vi body</p>'], $this->adopted->revision);
        DB::table('cms_content_translations')->where('content_id', $this->adopted->id)->where('locale', 'en')->update(['content' => '']);
        auth()->logout();
        $this->setOwnerActive(false);

        $this->assertSame(200, $this->httpStatus($this->adopted, 'vi'));
        $this->assertSame(404, $this->httpStatus($this->adopted, 'en'));
        $this->assertTrue($this->inSitemap($this->adopted, 'vi'));
        $this->assertFalse($this->inSitemap($this->adopted, 'en'));
    }

    public function test_existing_managed_rows_without_a_policy_keep_their_pre_upgrade_behaviour(): void
    {
        // A Line B (.37) row: managed_by set, no policy row.
        DB::table('cms_content_owner_policies')->where('content_id', $this->created->id)->delete();
        $this->setOwnerActive(false);

        $this->assertSame(200, $this->httpStatus($this->created), 'defaults: available + core_render (as before)');
        $this->assertContains($this->created->id, $this->discoverable());
    }

    public function test_the_front_page_is_never_owned_and_home_renders(): void
    {
        settings()->set('reading.homepage_display', 'static_page');
        settings()->set('reading.homepage_page_id', $this->core->id, 'integer');
        $this->get('/')->assertOk();
        $this->assertNull($this->core->refresh()->managed_by);
    }
}
