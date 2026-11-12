<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;

/**
 * CORE-40-FRONTEND-MEDIA-R1 Gate T5 (in-process) — GET/POST /account/media HTTP contract.
 */
final class FrontendMediaHttpContractTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    public function test_routes_are_registered_with_the_documented_names_and_middleware(): void
    {
        $index = Route::getRoutes()->getByName('cms.account.media.index');
        $store = Route::getRoutes()->getByName('cms.account.media.store');

        $this->assertSame('account/media', $index?->uri());
        $this->assertSame('account/media', $store?->uri());
        foreach ([$index, $store] as $route) {
            // CORE-40-ACCOUNT-SHELL-LOCALE-R1: Core's locale runtime runs before cms.auth.
            $this->assertSame(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'], array_slice($route->gatherMiddleware(), 0, 4));
        }
        $this->assertContains('throttle:cms-frontend-media-upload', $store->gatherMiddleware());
        $this->assertContains('throttle:cms-frontend-media-list', $index->gatherMiddleware());
    }

    public function test_list_response_shape_and_cursor_meta(): void
    {
        $author = $this->userWithRole('author');
        for ($i = 0; $i < 3; $i++) {
            $this->mediaRow($author);
        }

        $first = $this->actingAs($author)->getJson('/account/media?per_page=2')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonStructure(['data' => [['id', 'url', 'width', 'height', 'mime_type', 'title', 'alt', 'created_at']], 'meta' => ['next_cursor', 'per_page']]);
        $this->assertCount(2, $first->json('data'));

        $cursor = $first->json('meta.next_cursor');
        $second = $this->actingAs($author)->getJson('/account/media?per_page=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertCount(1, $second->json('data'));
        $this->assertNull($second->json('meta.next_cursor'));
    }

    public function test_search_query_and_invalid_input(): void
    {
        $author = $this->userWithRole('author');
        $this->mediaRow($author, ['original_filename' => 'harbour.jpg']);
        $this->mediaRow($author, ['original_filename' => 'forest.jpg']);

        $this->actingAs($author)->getJson('/account/media?q=harb')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($author)->getJson('/account/media?cursor=%%%')->assertStatus(422)->assertJsonPath('error.code', 'media_invalid_query');
        $this->actingAs($author)->getJson('/account/media?q='.str_repeat('a', 101))->assertStatus(422)->assertJsonPath('error.code', 'media_invalid_query');
        $this->actingAs($author)->getJson('/account/media?per_page=abc')->assertStatus(422)->assertJsonPath('error.code', 'media_invalid_query');
    }

    public function test_upload_returns_201_with_summary_and_never_exposes_paths(): void
    {
        $author = $this->userWithRole('author');

        $response = $this->actingAs($author)->post('/account/media', ['file' => $this->realImage('Hero.webp', 'webp', 64, 48)], ['Accept' => 'application/json'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString(str_replace('\\', '\\\\', $this->mediaPublicPath), $body);
        $this->assertSame('image/webp', $response->json('data.mime_type'));
    }

    public function test_guest_receives_401_json_or_a_login_redirect(): void
    {
        $this->getJson('/account/media')->assertUnauthorized();
        $this->postJson('/account/media')->assertUnauthorized();
        $this->get('/account/media')->assertRedirect(route('cms.auth.login'));
    }

    public function test_an_invalidated_frontend_session_is_rejected(): void
    {
        $author = $this->userWithRole('author');
        $author->forceFill(['frontend_session_version' => 5])->save();

        $this->actingAs($author)->withSession(['cms_auth' => ['version' => 1]])
            ->getJson('/account/media')->assertUnauthorized();
    }

    public function test_route_is_not_captured_by_the_frontend_catch_all_and_unknown_methods_are_405(): void
    {
        $author = $this->userWithRole('author');

        $this->actingAs($author)->getJson('/account/media')->assertOk();
        $this->actingAs($author)->deleteJson('/account/media')->assertStatus(405);
        $this->actingAs($author)->getJson('/account/media/1')->assertNotFound();
    }

    public function test_existing_account_routes_are_unaffected(): void
    {
        $author = $this->userWithRole('author');

        foreach (['/account', '/account/profile', '/account/security', '/account/sessions', '/account/preferences'] as $uri) {
            $this->actingAs($author)->get($uri)->assertOk();
        }
    }

    public function test_frontend_media_access_does_not_open_the_admin_panel(): void
    {
        $user = $this->userWithPermissions(['media.view_own', 'media.upload_own']);

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->getJson('/admin/cms-media-search')->assertForbidden();
        $this->actingAs($user)->get('/admin/media/upload')->assertForbidden();
    }
}
