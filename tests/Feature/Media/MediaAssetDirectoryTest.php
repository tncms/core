<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\InvalidMediaLookup;
use TheNguyen\CMS\Media\MediaAsset;
use TheNguyen\CMS\Media\MediaAssetDirectory;

/**
 * CORE-P12-1 — asset-by-ID lookup contract `app('cms.media.assets')`: explicit scopes, no
 * enumeration, no cross-user disclosure, bounded batch, one query, no paths in the result.
 */
final class MediaAssetDirectoryTest extends TestCase
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

    private function directory(): MediaAssetDirectory
    {
        return app('cms.media.assets');
    }

    public function test_container_binding_and_alias_resolve_the_same_singleton(): void
    {
        $this->assertInstanceOf(MediaAssetDirectory::class, app('cms.media.assets'));
        $this->assertSame(app('cms.media.assets'), app(MediaAssetDirectory::class));
    }

    public function test_public_lookup_returns_dtos_in_request_order_and_never_paths(): void
    {
        $owner = $this->userWithRole('author');
        $a = $this->mediaRow($owner);
        $b = $this->mediaRow($owner);

        $result = $this->directory()->lookupPublic([$b->id, (string) $a->id]);

        $this->assertSame([$b->id, $a->id], array_keys($result->found));
        $this->assertSame([], $result->missing);
        $asset = $result->get($a->id);
        $this->assertInstanceOf(MediaAsset::class, $asset);
        $this->assertSame($a->url, $asset->url);
        $this->assertSame(MediaAsset::VISIBILITY_PUBLIC, $asset->visibility);
        $this->assertTrue($asset->isImage());
        $array = $asset->toArray();
        foreach (['path', 'disk', 'uploaded_by', 'folder', 'filename', 'original_filename'] as $secret) {
            $this->assertArrayNotHasKey($secret, $array);
        }
        $this->assertStringNotContainsString('uploads/2026/11/row', json_encode(array_diff_key($array, ['url' => 1])) ?: '');
    }

    public function test_unknown_deleted_and_non_public_disk_ids_are_indistinguishably_missing(): void
    {
        $owner = $this->userWithRole('author');
        $live = $this->mediaRow($owner);
        $trashed = $this->mediaRow($owner);
        $trashed->delete();
        $private = $this->mediaRow($owner, ['disk' => 'local']);

        $result = $this->directory()->lookupPublic([$live->id, $trashed->id, $private->id, 999_999]);

        $this->assertSame([$live->id], array_keys($result->found));
        $this->assertSame([$trashed->id, $private->id, 999_999], $result->missing);
        $this->assertNull($this->directory()->findPublic($trashed->id));
    }

    public function test_hostile_ids_are_rejected_as_invalid_without_querying_them(): void
    {
        $owner = $this->userWithRole('author');
        $row = $this->mediaRow($owner);

        $result = $this->directory()->lookupPublic([
            (string) $row->id, $row->id, '0', '-1', '01', ' 1', '1 ', '1e3', '0x1', '1;DROP', '../1', null, 1.5, [], true, 0, -5,
        ]);

        $this->assertSame([$row->id], array_keys($result->found));
        $this->assertSame([], $result->missing);
        $this->assertCount(15, $result->invalid);
        foreach ($result->invalid as $echo) {
            $this->assertLessThanOrEqual(32, mb_strlen($echo));
        }
    }

    public function test_batch_cap_is_enforced_after_de_duplication(): void
    {
        $this->assertCount(0, $this->directory()->lookupPublic(array_fill(0, 1000, 7))->found);   // one distinct id

        $this->expectException(InvalidMediaLookup::class);
        $this->directory()->lookupPublic(range(1, MediaAssetDirectory::MAX_IDS + 1));
    }

    public function test_reason_code_is_carried_by_the_exception(): void
    {
        try {
            $this->directory()->lookupPublic(range(1, MediaAssetDirectory::MAX_IDS + 1));
            $this->fail('expected InvalidMediaLookup');
        } catch (InvalidMediaLookup $e) {
            $this->assertSame(InvalidMediaLookup::REASON_TOO_MANY_IDS, $e->reason);
        }
    }

    public function test_a_full_batch_costs_exactly_one_query(): void
    {
        $owner = $this->userWithRole('author');
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = $this->mediaRow($owner)->id;
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->directory()->lookupPublic(array_merge($ids, range(900_000, 900_149)));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(50, $result->found);
        $this->assertCount(150, $result->missing);
        $this->assertCount(1, $queries);
    }

    public function test_owned_scope_never_discloses_another_users_asset(): void
    {
        $alice = $this->userWithRole('author');
        $bob = $this->userWithRole('author');
        $mine = $this->mediaRow($alice);
        $theirs = $this->mediaRow($bob);
        $orphan = $this->mediaRow(null);

        $result = $this->directory()->lookupOwned($alice, [$mine->id, $theirs->id, $orphan->id]);

        $this->assertSame([$mine->id], array_keys($result->found));
        $this->assertSame([$theirs->id, $orphan->id], $result->missing);
    }

    public function test_owned_scope_needs_view_own_and_an_actor(): void
    {
        $subscriber = $this->userWithRole('subscriber');
        $row = $this->mediaRow($subscriber);

        $this->assertSame([], $this->directory()->lookupOwned($subscriber, [$row->id])->found);
        $this->assertSame([$row->id], $this->directory()->lookupOwned($subscriber, [$row->id])->missing);
        $this->assertSame([], $this->directory()->lookupOwned(null, [$row->id])->found);
    }

    public function test_managed_scope_needs_media_view_strictly(): void
    {
        $ownOnly = $this->userWithPermissions(['media.view_own', 'media.upload_own'], 'own-only');
        $viewer = $this->userWithPermissions(['media.view']);
        $row = $this->mediaRow($this->userWithRole('subscriber'));

        $this->assertSame([$row->id], array_keys($this->directory()->lookupManaged($viewer, [$row->id])->found));
        $this->assertSame([], $this->directory()->lookupManaged($ownOnly, [$row->id])->found);
        $this->assertSame([$row->id], $this->directory()->lookupManaged($ownOnly, [$row->id])->missing);
        $this->assertSame([], $this->directory()->lookupManaged(null, [$row->id])->found);
    }

    public function test_absolute_url_uses_configured_origin_not_the_request_host(): void
    {
        config(['app.url' => 'https://cms.example.test']);
        $row = $this->mediaRow(null);
        $asset = $this->directory()->findPublic($row->id);
        $this->assertNotNull($asset);

        $this->get('/', ['Host' => 'evil.example']);

        $this->assertSame('https://cms.example.test'.$row->url, $this->directory()->absoluteUrl($asset));
    }
}
