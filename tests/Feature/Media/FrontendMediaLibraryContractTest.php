<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\Frontend\FrontendMediaException;
use TheNguyen\CMS\Media\Frontend\FrontendMediaLibrary;
use TheNguyen\CMS\Media\Frontend\FrontendMediaPage;
use TheNguyen\CMS\Media\Frontend\FrontendMediaQuery;
use TheNguyen\CMS\Media\Frontend\MediaSummary;

/**
 * CORE-40-FRONTEND-MEDIA-R1 Gate T1 — service contract of app('cms.media.frontend').
 */
final class FrontendMediaLibraryContractTest extends TestCase
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

    private function library(): FrontendMediaLibrary
    {
        return app('cms.media.frontend');
    }

    public function test_service_is_bound_as_a_singleton(): void
    {
        $this->assertInstanceOf(FrontendMediaLibrary::class, app('cms.media.frontend'));
        $this->assertSame(app('cms.media.frontend'), app(FrontendMediaLibrary::class));
    }

    public function test_list_own_returns_only_the_actors_non_deleted_images_newest_first(): void
    {
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $a1 = $this->mediaRow($author);
        $this->mediaRow($other);
        $this->mediaRow(null);
        $this->mediaRow($author, ['mime_type' => 'application/pdf', 'extension' => 'pdf']);
        $trashed = $this->mediaRow($author);
        $trashed->delete();
        $a2 = $this->mediaRow($author);

        $page = $this->library()->listOwn($author, new FrontendMediaQuery);

        $this->assertInstanceOf(FrontendMediaPage::class, $page);
        $this->assertSame([$a2->id, $a1->id], array_map(static fn (MediaSummary $m): int => $m->id, $page->items));
        $this->assertNull($page->nextCursor);
    }

    public function test_pagination_is_bounded_to_48_and_cursor_walks_every_item_exactly_once(): void
    {
        $author = $this->userWithRole('author');
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $ids[] = $this->mediaRow($author)->id;
        }

        $first = $this->library()->listOwn($author, new FrontendMediaQuery(perPage: 500));
        $this->assertCount(48, $first->items);
        $this->assertSame(48, $first->perPage);
        $this->assertNotNull($first->nextCursor);

        $second = $this->library()->listOwn($author, new FrontendMediaQuery(cursor: $first->nextCursor, perPage: 500));
        $this->assertCount(12, $second->items);
        $this->assertNull($second->nextCursor);

        $seen = array_map(static fn (MediaSummary $m): int => $m->id, [...$first->items, ...$second->items]);
        $this->assertSame(array_reverse($ids), $seen);
    }

    public function test_items_created_between_pages_do_not_duplicate_or_skip(): void
    {
        $author = $this->userWithRole('author');
        $old = [];
        for ($i = 0; $i < 5; $i++) {
            $old[] = $this->mediaRow($author)->id;
        }
        $first = $this->library()->listOwn($author, new FrontendMediaQuery(perPage: 2));
        $this->mediaRow($author); // newer item appears while paging
        $rest = [];
        $cursor = $first->nextCursor;
        while ($cursor !== null) {
            $page = $this->library()->listOwn($author, new FrontendMediaQuery(cursor: $cursor, perPage: 2));
            array_push($rest, ...array_map(static fn (MediaSummary $m): int => $m->id, $page->items));
            $cursor = $page->nextCursor;
        }
        $walked = [...array_map(static fn (MediaSummary $m): int => $m->id, $first->items), ...$rest];
        $this->assertSame(array_reverse($old), $walked);
    }

    public function test_a_malformed_cursor_is_rejected(): void
    {
        $author = $this->userWithRole('author');

        $this->expectException(FrontendMediaException::class);
        $this->library()->listOwn($author, new FrontendMediaQuery(cursor: 'not-a-cursor'));
    }

    public function test_search_matches_filename_and_title_and_escapes_wildcards(): void
    {
        $author = $this->userWithRole('author');
        $beach = $this->mediaRow($author, ['original_filename' => 'Beach day.jpg', 'title' => null]);
        $titled = $this->mediaRow($author, ['original_filename' => 'x.jpg', 'title' => 'Sunset at the beach']);
        $percent = $this->mediaRow($author, ['original_filename' => '100%_done.jpg', 'title' => null]);
        $this->mediaRow($author, ['original_filename' => '100 xdone.jpg', 'title' => null]);

        $ids = fn (string $q): array => array_map(static fn (MediaSummary $m): int => $m->id, $this->library()->listOwn($author, new FrontendMediaQuery(search: $q))->items);

        $this->assertSame([$titled->id, $beach->id], $ids('beach'));
        $this->assertSame([$percent->id], $ids('100%_'));
        $this->assertSame([$percent->id], $ids('%'));
        $this->assertSame([], $ids('zzz-no-match'));
    }

    public function test_summary_exposes_only_the_documented_public_fields(): void
    {
        $author = $this->userWithRole('author');
        $media = $this->mediaRow($author, ['description' => 'private-ish', 'caption' => 'cap']);

        $summary = $this->library()->resolveForActor($author, $media->id);

        $this->assertNotNull($summary);
        $this->assertSame(['id', 'url', 'width', 'height', 'mime_type', 'title', 'alt', 'created_at'], array_keys($summary->toArray()));
        $this->assertSame($media->url, $summary->url);
        $json = json_encode($summary->toArray());
        $this->assertStringNotContainsString('uploads/2026/11/'.$media->filename.'"', str_replace($media->url, '', (string) $json));
        $this->assertStringNotContainsString('"disk"', (string) $json);
        $this->assertStringNotContainsString('"path"', (string) $json);
        $this->assertStringNotContainsString('uploaded_by', (string) $json);
    }

    public function test_resolve_for_actor_denies_foreign_deleted_missing_and_non_image_media(): void
    {
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $foreign = $this->mediaRow($other);
        $deleted = $this->mediaRow($author);
        $deleted->delete();
        $pdf = $this->mediaRow($author, ['mime_type' => 'application/pdf', 'extension' => 'pdf']);
        $own = $this->mediaRow($author);

        $this->assertSame($own->id, $this->library()->resolveForActor($author, $own->id)?->id);
        $this->assertNull($this->library()->resolveForActor($author, $foreign->id));
        $this->assertNull($this->library()->resolveForActor($author, $deleted->id));
        $this->assertNull($this->library()->resolveForActor($author, $pdf->id));
        $this->assertNull($this->library()->resolveForActor($author, 999999));
        $this->assertNull($this->library()->resolveForActor($author, 0));
    }

    public function test_resolve_many_returns_only_authorized_ids_keyed_by_id(): void
    {
        $author = $this->userWithRole('author');
        $own1 = $this->mediaRow($author);
        $own2 = $this->mediaRow($author);
        $foreign = $this->mediaRow($this->userWithRole('author'));

        $resolved = $this->library()->resolveManyForActor($author, [$own2->id, $foreign->id, $own1->id, $own1->id, -5]);

        $this->assertSame([$own1->id, $own2->id], array_keys($resolved));
    }

    public function test_privileged_resolution_requires_media_view_and_is_never_used_by_list_own(): void
    {
        $owner = $this->userWithRole('author');
        $media = $this->mediaRow($owner);
        $editor = $this->userWithRole('editor'); // media.view
        $author = $this->userWithPermissions(['media.view_own']); // no media.view

        $this->assertSame($media->id, $this->library()->resolvePrivileged($editor, $media->id)?->id);
        $this->assertNull($this->library()->resolvePrivileged($author, $media->id));
        $this->assertSame([], $this->library()->listOwn($editor, new FrontendMediaQuery)->items);
    }

    public function test_upload_own_forces_ownership_to_the_actor(): void
    {
        $author = $this->userWithRole('author');
        $someoneElse = $this->userWithRole('author');
        $this->actingAs($someoneElse); // the ambient auth user must not leak into ownership

        $summary = $this->library()->uploadOwn($author, $this->realImage('Cover photo.jpg'));

        $this->assertSame($author->id, (int) \TheNguyen\CMS\Models\Media::query()->findOrFail($summary->id)->uploaded_by);
        $this->assertSame('image/jpeg', $summary->mimeType);
        $this->assertSame(32, $summary->width);
        $this->assertStringStartsWith('/uploads/', $summary->url);
    }

    public function test_list_own_requires_view_own_permission(): void
    {
        $subscriber = $this->userWithRole('subscriber');

        try {
            $this->library()->listOwn($subscriber, new FrontendMediaQuery);
            $this->fail('expected denial');
        } catch (FrontendMediaException $e) {
            $this->assertSame('media_permission_denied', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }
}
