<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\Frontend\FrontendMediaException;
use TheNguyen\CMS\Media\Frontend\FrontendMediaLibrary;
use TheNguyen\CMS\Models\Media;

/**
 * CORE-P12-1 — owner self-deletion: `deleteOwn()` and DELETE /account/media/{id}.
 */
final class FrontendMediaOwnerDeleteTest extends TestCase
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
        app(\TheNguyen\CMS\Services\HookManager::class)->removeFilter('cms.media.in_use');
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    private function library(): FrontendMediaLibrary
    {
        return app('cms.media.frontend');
    }

    private function uploaded(\App\Models\User $owner): Media
    {
        $summary = $this->library()->uploadOwn($owner, $this->realImage('owned.jpg'));

        return Media::query()->findOrFail($summary->id);
    }

    private function fileOf(Media $media): string
    {
        return $this->mediaPublicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $media->path);
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("expected {$code}");
        } catch (FrontendMediaException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_default_roles_grant_delete_own_only_where_upload_own_is_granted(): void
    {
        foreach (['author' => true, 'editor' => true, 'subscriber' => false] as $role => $expected) {
            $this->assertSame($expected, $this->library()->canDeleteOwn($this->userWithRole($role)), $role);
        }
        $this->assertFalse($this->library()->canDeleteOwn(null));
    }

    public function test_owner_deletes_own_asset_row_first_then_file_and_quota_is_freed(): void
    {
        $author = $this->userWithRole('author');
        $media = $this->uploaded($author);
        $this->assertFileExists($this->fileOf($media));
        $used = $this->library()->quotaUsage($author)['used_bytes'];
        $this->assertGreaterThan(0, $used);

        $this->library()->deleteOwn($author, (int) $media->id);

        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileDoesNotExist($this->fileOf($media));
        $this->assertSame(0, $this->library()->quotaUsage($author)['used_bytes']);
    }

    public function test_foreign_unknown_and_already_deleted_ids_are_the_same_not_found(): void
    {
        $alice = $this->userWithRole('author');
        $bob = $this->userWithRole('author');
        $bobs = $this->uploaded($bob);
        $gone = $this->uploaded($alice);
        $this->library()->deleteOwn($alice, (int) $gone->id);

        foreach ([(int) $bobs->id, (int) $gone->id, 999_999, 0, -3] as $id) {
            $this->assertRefused(FrontendMediaException::NOT_FOUND, fn () => $this->library()->deleteOwn($alice, $id));
        }
        $this->assertFileExists($this->fileOf($bobs));
        $this->assertNotSoftDeleted('cms_media', ['id' => $bobs->id]);
    }

    public function test_without_the_permission_nothing_is_deleted(): void
    {
        $uploader = $this->userWithPermissions(['media.view_own', 'media.upload_own']);
        $media = $this->uploaded($uploader);

        $this->assertRefused(FrontendMediaException::PERMISSION_DENIED, fn () => $this->library()->deleteOwn($uploader, (int) $media->id));
        $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists($this->fileOf($media));
    }

    public function test_media_view_is_not_a_licence_to_delete_others(): void
    {
        $viewer = $this->userWithPermissions(['media.view', 'media.view_own', 'media.upload_own', 'media.delete_own']);
        $other = $this->mediaRow($this->userWithRole('author'));

        $this->assertRefused(FrontendMediaException::NOT_FOUND, fn () => $this->library()->deleteOwn($viewer, (int) $other->id));
    }

    public function test_an_asset_referenced_by_core_content_is_refused_with_in_use(): void
    {
        $author = $this->userWithRole('author');
        $byUrl = $this->uploaded($author);
        $byId = $this->uploaded($author);
        $byWidget = $this->uploaded($author);
        $this->insertContent(['featured_image' => $byUrl->url]);
        $this->insertContent(['featured_image' => (string) $byId->id]);
        DB::table('cms_widgets')->insert($this->widgetRow(json_encode(['image' => $byWidget->url])));

        foreach ([$byUrl, $byId, $byWidget] as $media) {
            $this->assertRefused(FrontendMediaException::IN_USE, fn () => $this->library()->deleteOwn($author, (int) $media->id));
            $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);
            $this->assertFileExists($this->fileOf($media));
        }
    }

    public function test_a_plugin_can_veto_through_the_in_use_filter(): void
    {
        $author = $this->userWithRole('author');
        $media = $this->uploaded($author);
        $seen = [];
        add_filter('cms.media.in_use', function (bool $inUse, int $id, string $url) use (&$seen): bool {
            $seen = [$id, $url];

            return true;
        }, 10, 3);

        $this->assertRefused(FrontendMediaException::IN_USE, fn () => $this->library()->deleteOwn($author, (int) $media->id));
        $this->assertSame([(int) $media->id, (string) $media->url], $seen);
        $this->assertFileExists($this->fileOf($media));
    }

    public function test_like_wildcards_in_a_url_do_not_create_false_matches(): void
    {
        $author = $this->userWithRole('author');
        $media = $this->uploaded($author);
        $media->forceFill(['url' => '/uploads/a_b%c.jpg'])->saveQuietly();
        DB::table('cms_widgets')->insert($this->widgetRow(json_encode(['image' => '/uploads/aXbYc.jpg'])));

        $this->library()->deleteOwn($author, (int) $media->id);
        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
    }

    public function test_http_route_is_registered_with_auth_and_throttle(): void
    {
        $route = Route::getRoutes()->getByName('cms.account.media.destroy');
        $this->assertSame('account/media/{media}', $route?->uri());
        $this->assertSame(['DELETE'], $route->methods());
        $this->assertSame(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'], array_slice($route->gatherMiddleware(), 0, 4));
        $this->assertContains('throttle:cms-frontend-media-delete', $route->gatherMiddleware());
        $this->assertNotNull(Route::getRoutes()->getByName('cms.account.media.destroy.localized'));
    }

    public function test_http_delete_contract(): void
    {
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $media = $this->uploaded($author);
        $foreign = $this->uploaded($other);

        $this->deleteJson('/account/media/'.$media->id)->assertStatus(401);
        $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);

        $this->actingAs($author)->deleteJson('/account/media/'.$foreign->id)->assertStatus(404)
            ->assertJsonPath('error.code', FrontendMediaException::NOT_FOUND);
        foreach (['abc', '0', '01', '1e2'] as $bad) {
            $this->actingAs($author)->deleteJson('/account/media/'.$bad)->assertStatus(404);
        }

        $this->actingAs($author)->deleteJson('/account/media/'.$media->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.deleted', true)
            ->assertJsonPath('data.quota.used_bytes', 0);
        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileDoesNotExist($this->fileOf($media));

        // A duplicate request is a clean 404, not an error.
        $this->actingAs($author)->deleteJson('/account/media/'.$media->id)->assertStatus(404);
    }

    public function test_http_delete_is_csrf_protected_when_verification_runs(): void
    {
        $author = $this->userWithRole('author');
        $this->actingAs($author);
        $request = \Illuminate\Http\Request::create('/account/media/1', 'DELETE', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession($this->app['session']->driver());
        $middleware = new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        $this->expectException(\Illuminate\Session\TokenMismatchException::class);
        $middleware->handle($request, static fn () => response('passed'));
    }

    public function test_http_delete_denies_subscriber_with_403(): void
    {
        $subscriber = $this->userWithRole('subscriber');
        $row = $this->mediaRow($subscriber);

        $this->actingAs($subscriber)->deleteJson('/account/media/'.$row->id)->assertStatus(403)
            ->assertJsonPath('error.code', FrontendMediaException::PERMISSION_DENIED);
        $this->assertNotSoftDeleted('cms_media', ['id' => $row->id]);
    }

    public function test_http_delete_in_use_is_409(): void
    {
        $author = $this->userWithRole('author');
        $media = $this->uploaded($author);
        $this->insertContent(['featured_image' => $media->url]);

        $this->actingAs($author)->deleteJson('/account/media/'.$media->id)->assertStatus(409)
            ->assertJsonPath('error.code', FrontendMediaException::IN_USE);
    }

    public function test_http_delete_is_rate_limited_per_user(): void
    {
        config(['cms.media.frontend.deletes_per_minute' => 2]);
        $author = $this->userWithRole('author');

        $this->actingAs($author)->deleteJson('/account/media/999991')->assertStatus(404);
        $this->actingAs($author)->deleteJson('/account/media/999992')->assertStatus(404);
        $this->actingAs($author)->deleteJson('/account/media/999993')->assertStatus(429);
    }

    public function test_upload_has_a_per_ip_ceiling_across_accounts(): void
    {
        config(['cms.media.frontend.ip_uploads_per_minute' => 2, 'cms.media.frontend.uploads_per_minute' => 50]);

        foreach ([201, 201, 429] as $i => $status) {
            $user = $this->userWithRole('author');
            $this->actingAs($user)->post('/account/media', ['file' => $this->realImage("ip{$i}.jpg")], ['Accept' => 'application/json'])
                ->assertStatus($status);
        }
    }

    /** @param  array<string, mixed>  $overrides */
    private function insertContent(array $overrides): void
    {
        DB::table('cms_contents')->insert(array_merge([
            'type' => 'post',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function widgetRow(string $settings): array
    {
        $columns = array_flip(DB::getSchemaBuilder()->getColumnListing('cms_widgets'));
        $row = ['settings' => $settings, 'created_at' => now(), 'updated_at' => now()];
        foreach (['widget_type' => 'image', 'type' => 'image', 'area' => 'sidebar', 'sidebar' => 'sidebar', 'title' => 'W', 'position' => 0, 'order' => 0, 'sort_order' => 0, 'is_active' => 1] as $col => $value) {
            if (isset($columns[$col])) {
                $row[$col] = $value;
            }
        }

        return array_intersect_key($row, $columns);
    }
}
