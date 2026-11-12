<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\Frontend\FrontendMediaLibrary;
use TheNguyen\CMS\Media\Frontend\FrontendMediaQuery;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-40-FRONTEND-MEDIA-R1 §8 — a disposable consumer harness that behaves like a
 * structured-content plugin's frontend author (Toplist-shaped): it keeps its own draft and
 * published documents and references Core media by id. It never reads cms_media directly;
 * every decision goes through app('cms.media.frontend'). The real Toplist plugin is untouched.
 */
final class FrontendMediaConsumerContractTest extends TestCase
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

    private function consumer(): object
    {
        return new class(app('cms.media.frontend'))
        {
            /** @var array{cover: ?int, gallery: list<array{media: int, caption: string}>} */
            public array $draft = ['cover' => null, 'gallery' => []];

            /** @var array{cover: ?int, gallery: list<array{media: int, caption: string}>} */
            public array $published = ['cover' => null, 'gallery' => []];

            public function __construct(private readonly FrontendMediaLibrary $media) {}

            /** Attach an image to the draft; refused unless Core authorizes the reference. */
            public function attach(User $actor, int $mediaId, string $caption = ''): bool
            {
                if ($this->media->resolveForActor($actor, $mediaId) === null) {
                    return false;
                }
                $this->draft['gallery'][] = ['media' => $mediaId, 'caption' => $caption];

                return true;
            }

            public function setCover(User $actor, int $mediaId): bool
            {
                if ($this->media->resolveForActor($actor, $mediaId) === null) {
                    return false;
                }
                $this->draft['cover'] = $mediaId;

                return true;
            }

            public function publish(): void
            {
                $this->published = $this->draft;
            }

            /** Render URLs for the author's editor, re-authorizing every reference in one query. */
            public function editorPreview(User $actor): array
            {
                $ids = array_column($this->draft['gallery'], 'media');
                $resolved = $this->media->resolveManyForActor($actor, $ids);

                return array_map(static fn (array $item): ?string => ($resolved[$item['media']] ?? null)?->url, $this->draft['gallery']);
            }
        };
    }

    public function test_frontend_author_lists_uploads_resolves_and_references_own_media(): void
    {
        $author = $this->userWithPermissions(['media.view_own', 'media.upload_own'], 'writer');
        $this->assertFalse(cms_can('admin.access', $author));
        $consumer = $this->consumer();

        // 1. list (empty) and 2. upload without admin access (over HTTP, as the plugin UI would).
        $this->actingAs($author)->getJson('/account/media')->assertOk()->assertJsonCount(0, 'data');
        $id = (int) $this->actingAs($author)->post('/account/media', ['file' => $this->realImage('one.jpg')], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->assertSame([$id], array_column($this->actingAs($author)->getJson('/account/media')->json('data'), 'id'));

        // 3. resolve own id and 5. store it in the draft.
        $this->assertTrue($consumer->setCover($author, $id));
        $this->assertTrue($consumer->attach($author, $id, 'Caption owned by the plugin'));
        $this->assertSame($id, $consumer->draft['cover']);
        $this->assertNotNull($consumer->editorPreview($author)[0]);
    }

    public function test_foreign_media_is_rejected_and_the_published_document_is_preserved(): void
    {
        $author = $this->userWithRole('author');
        $intruder = $this->userWithRole('author');
        $consumer = $this->consumer();
        $own = app('cms.media.frontend')->uploadOwn($author, $this->realImage('own.jpg'));
        $foreign = app('cms.media.frontend')->uploadOwn($intruder, $this->realImage('foreign.jpg'));

        $consumer->attach($author, $own->id);
        $consumer->publish();
        $snapshot = $consumer->published;

        // 4. reject another user's media id, 6. published document unchanged.
        $this->assertFalse($consumer->attach($author, $foreign->id));
        $this->assertFalse($consumer->setCover($author, $foreign->id));
        $this->assertSame($snapshot, $consumer->published);
        $this->assertSame([['media' => $own->id, 'caption' => '']], $consumer->draft['gallery']);
    }

    public function test_revoking_upload_keeps_reading_and_revoking_view_fails_safely(): void
    {
        $author = $this->userWithPermissions(['media.view_own', 'media.upload_own'], 'writer');
        $consumer = $this->consumer();
        $media = app('cms.media.frontend')->uploadOwn($author, $this->realImage('kept.jpg'));
        $consumer->attach($author, $media->id);

        $role = Role::query()->where('slug', 'writer')->firstOrFail();

        // 7. upload revoked, view kept: existing references still resolve; new uploads refused.
        $role->permissions()->detach(Permission::query()->where('slug', 'media.upload_own')->value('id'));
        $this->flushPermissions();
        $this->assertSame($media->url, $consumer->editorPreview($author)[0]);
        $this->actingAs($author)->post('/account/media', ['file' => $this->realImage('new.jpg')], ['Accept' => 'application/json'])->assertForbidden();

        // 8. view revoked: resolution fails closed (null), listing is a 403, the draft keeps its ids.
        $role->permissions()->detach(Permission::query()->where('slug', 'media.view_own')->value('id'));
        $this->flushPermissions();
        $this->assertSame([null], $consumer->editorPreview($author));
        $this->assertFalse($consumer->attach($author, $media->id));
        $this->actingAs($author)->getJson('/account/media')->assertForbidden();
        $this->assertSame([['media' => $media->id, 'caption' => '']], $consumer->draft['gallery']);
        $this->assertSame([], app('cms.media.frontend')->resolveManyForActor($author, [$media->id]));
    }

    public function test_consumer_cannot_widen_scope_through_the_query_object(): void
    {
        $author = $this->userWithRole('author');
        $other = $this->userWithRole('author');
        $this->mediaRow($other);

        $page = app('cms.media.frontend')->listOwn($author, new FrontendMediaQuery(search: '', perPage: 48));

        $this->assertSame([], $page->items);
    }
}
