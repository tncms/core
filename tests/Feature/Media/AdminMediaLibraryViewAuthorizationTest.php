<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Filament\Admin\Components\MediaLibrarySelect;
use App\Filament\Admin\Resources\PostResource\Pages\CreatePost;
use App\Filament\Admin\Resources\PostResource\Pages\EditPost;
use App\Filament\Admin\Support\MediaItems;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-40-MEDIA-LIBRARY-VIEW-AUTH-H1 — admin media discovery (picker library grid,
 * selected-item metadata, rich-editor Insert Media snapshot, search) requires
 * media.view on every request; admin.access, media.upload and the frontend own-media
 * permissions never substitute for it. Real host pages, real Filament actions.
 */
final class AdminMediaLibraryViewAuthorizationTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    private const SECRET_NAME = 'zz-unrelated-library-secret.jpg';

    private Media $unrelated;

    private Media $selected;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();
        $this->unrelated = $this->mediaRow(null, [
            'filename' => self::SECRET_NAME, 'original_filename' => self::SECRET_NAME,
            'path' => 'uploads/2026/11/'.self::SECRET_NAME, 'url' => '/uploads/2026/11/'.self::SECRET_NAME,
            'alt' => 'Unrelated secret alt',
        ]);
        $this->selected = $this->mediaRow(null, [
            'filename' => 'zz-selected-record.jpg', 'original_filename' => 'zz-selected-original-name.jpg',
            'path' => 'uploads/2026/11/zz-selected-record.jpg', 'url' => '/uploads/2026/11/zz-selected-record.jpg',
            'alt' => 'Selected private alt', 'width' => 640, 'height' => 480,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    private const POST = ['posts.view', 'posts.create', 'posts.edit'];

    private function actor(array $media, string $role = 'lib-actor'): \App\Models\User
    {
        $user = $this->userWithPermissions(array_merge(['admin.access'], self::POST, $media), $role);
        $this->actingAs($user);

        return $user;
    }

    private function revoke(string $role, string $permission): void
    {
        Role::query()->where('slug', $role)->firstOrFail()->permissions()
            ->detach(Permission::query()->where('slug', $permission)->value('id'));
        $this->flushPermissions();
    }

    private function mountPicker(Testable $lw): Testable
    {
        return $lw->mountAction(TestAction::make('featured_image_select')->schemaComponent(true));
    }

    /** The library field of the mounted picker modal, resolved from the live component. */
    private function libraryField(Testable $lw): ?MediaLibrarySelect
    {
        $schema = $lw->instance()->getSchema('mountedActionSchema0');
        foreach ($schema?->getFlatComponents(withHidden: true) ?? [] as $component) {
            if ($component instanceof MediaLibrarySelect) {
                return $component;
            }
        }

        return null;
    }

    /** The picker action is neither rendered nor mountable (its Actions group is hidden too). */
    private function assertPickerNotOffered(Testable $lw): void
    {
        $this->assertStringNotContainsString('featured_image_select', $lw->html());
        try {
            $this->mountPicker($lw);
        } catch (\Filament\Actions\Exceptions\ActionNotResolvableException) {
        }
        $this->assertSame([], $lw->get('mountedActions') ?? []);
    }

    private function editPost(): Testable
    {
        $this->seed(\TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder::class);
        $post = app('cms.content')->create([
            'type' => 'post', 'status' => 'draft', 'locale' => 'vi', 'author_id' => auth()->id(),
            'title' => 'Library auth post', 'slug' => 'library-auth-post', 'content' => '<p>x</p>',
            'featured_image' => $this->selected->url,
        ], \Tests\Support\ContentFixtures::context());

        return Livewire::test(EditPost::class, ['record' => $post->getRouteKey()]);
    }

    private function assertNoUnrelatedDisclosure(string $html): void
    {
        $this->assertStringNotContainsString(self::SECRET_NAME, $html);
        $this->assertStringNotContainsString('Unrelated secret alt', $html);
    }

    // ---- matrix: browse

    public function test_1_view_only_actor_can_browse(): void
    {
        $this->actor(['media.view']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class));

        $lw->assertFormFieldVisible('selected_url');
        $urls = array_column($this->libraryField($lw)->getMediaItems(), 'url');
        $this->assertContains($this->unrelated->url, $urls);
    }

    public function test_2_view_only_actor_can_search(): void
    {
        $this->actor(['media.view']);
        $this->getJson(route('filament.admin.cms-media-search', ['q' => 'unrelated']))
            ->assertOk()->assertJsonPath('data.0.url', $this->unrelated->url);
    }

    public function test_3_view_only_actor_cannot_upload(): void
    {
        $this->actor(['media.view']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class));
        $lw->assertFormFieldHidden('uploaded_url');
    }

    public function test_4_upload_only_actor_cannot_browse(): void
    {
        $this->actor(['media.upload']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class));

        $lw->assertFormFieldHidden('selected_url');
        $this->assertSame([], $this->libraryField($lw)?->getMediaItems() ?? []);
        $this->assertSame([], MediaItems::imageItems(50));
        $this->assertNoUnrelatedDisclosure($lw->html());
    }

    public function test_5_upload_only_actor_cannot_search(): void
    {
        $this->actor(['media.upload']);
        $this->getJson(route('filament.admin.cms-media-search', ['q' => 'unrelated']))->assertForbidden();
        $this->assertSame([], MediaItems::searchImages('unrelated'));
    }

    public function test_6_upload_only_actor_can_upload_through_the_inline_picker(): void
    {
        $this->actor(['media.upload']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class))
            ->assertFormFieldVisible('uploaded_url')
            ->fillForm(['uploaded_url' => [UploadedFile::fake()->image('upload-only.jpg', 40, 30)]]);
        $lw->callMountedAction();

        $this->assertSame(3, Media::query()->count());
        $this->assertSame('/uploads/2026/11/upload-only.jpg', $lw->get('data.featured_image'));
    }

    public function test_7_actor_with_neither_permission_cannot_browse_or_upload(): void
    {
        $this->actor([]);
        $lw = Livewire::test(CreatePost::class)->assertOk();

        $this->assertPickerNotOffered($lw);
        $this->assertNoUnrelatedDisclosure($lw->html());
        $this->assertSame([], MediaItems::imageItems(50));
    }

    public function test_8_admin_access_alone_is_insufficient(): void
    {
        $user = $this->userWithPermissions(['admin.access'], 'panel-only');
        $this->actingAs($user);

        $this->assertSame([], MediaItems::imageItems(50));
        $this->assertSame([], MediaItems::searchImages(''));
        $this->assertNull(MediaItems::findByUrl($this->unrelated->url));
        $this->getJson(route('filament.admin.cms-media-search'))->assertForbidden();
    }

    public function test_frontend_own_media_permissions_do_not_authorize_admin_discovery(): void
    {
        $this->actor(['media.view_own', 'media.upload_own']);
        $lw = Livewire::test(CreatePost::class)->assertOk();

        $this->assertPickerNotOffered($lw);
        $this->assertSame([], MediaItems::imageItems(50));
        $this->assertNoUnrelatedDisclosure($lw->html());
    }

    // ---- revocation / forged / replayed

    public function test_9_revoking_media_view_blocks_the_next_livewire_library_request(): void
    {
        $this->actor(['media.view', 'media.upload']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class));
        $this->assertNotSame([], $this->libraryField($lw)->getMediaItems());

        $this->revoke('lib-actor', 'media.view');

        // Next Livewire request on the already-open modal.
        $lw->set('mountedActions.0.data.selected_url', '');
        $lw->assertFormFieldHidden('selected_url');
        $this->assertSame([], $this->libraryField($lw)?->getMediaItems() ?? []);
        // Filament skips the re-render of a state-only update, so Testable::html() is the
        // pre-revocation render; any HTML the server does return must be clean.
        $this->assertNoUnrelatedDisclosure((string) ($lw->effects['html'] ?? ''));
        // The next full render (page load / navigation) of the same form is clean.
        $this->assertNoUnrelatedDisclosure(Livewire::test(CreatePost::class)->html());
        $this->getJson(route('filament.admin.cms-media-search', ['q' => 'unrelated']))->assertForbidden();
    }

    public function test_10_forged_and_replayed_library_requests_reveal_nothing(): void
    {
        $this->actor(['media.upload']);
        $lw = Livewire::test(CreatePost::class);

        // Forged: mount the picker action directly and push the hidden library state path.
        try {
            $this->mountPicker($lw);
            $lw->set('mountedActions.0.data.selected_url', $this->unrelated->url);
        } catch (HttpException) {
        }
        $this->assertNoUnrelatedDisclosure($lw->html());
        $this->assertSame([], $this->libraryField($lw)?->getMediaItems() ?? []);
        $this->assertNull($this->libraryField($lw)?->getSelectedItem());

        // Replayed: a search URL captured while authorised is replayed after revocation.
        $this->actor(['media.view'], 'replayer');
        $url = route('filament.admin.cms-media-search', ['q' => 'unrelated', 'limit' => 50]);
        $this->getJson($url)->assertOk();
        $this->revoke('replayer', 'media.view');
        $response = $this->getJson($url)->assertForbidden();
        $this->assertStringNotContainsString(self::SECRET_NAME, (string) $response->getContent());
    }

    public function test_serialized_component_state_never_contains_the_library_snapshot(): void
    {
        $this->actor(['media.view']);
        $lw = $this->mountPicker(Livewire::test(CreatePost::class));

        $snapshot = json_encode($lw->snapshot);
        $this->assertStringNotContainsString(self::SECRET_NAME, $snapshot);
    }

    // ---- 11: selected URL policy

    public function test_11_selected_url_is_kept_and_previewed_without_library_metadata_for_non_viewers(): void
    {
        $this->actor(['media.upload']);
        $lw = $this->editPost();

        $this->assertSame($this->selected->url, $lw->get('data.featured_image'));
        $html = $lw->html();
        $this->assertStringContainsString($this->selected->url, $html, 'the selected image and its URL stay visible');
        $this->assertStringNotContainsString('zz-selected-original-name.jpg', $html, 'no library filename');
        $this->assertStringNotContainsString('Selected private alt', $html, 'no library alt text');
        $this->assertStringNotContainsString('640 × 480', $html, 'no library dimensions');
        $this->assertStringNotContainsString('External or missing media record', $html, 'no misleading status');
        $this->assertNoUnrelatedDisclosure($html);
    }

    public function test_11_selected_url_preview_keeps_metadata_for_viewers(): void
    {
        $this->actor(['media.view']);
        $html = $this->editPost()->html();

        $this->assertStringContainsString('zz-selected-original-name.jpg', $html);
        $this->assertStringContainsString('640 × 480', $html);
    }

    public function test_11_a_non_viewer_saving_the_form_keeps_the_selected_url(): void
    {
        $this->actor(['media.upload']);
        $lw = $this->editPost();
        $lw->set('data.title', 'Keeps its image')->call('save');

        $this->assertSame($this->selected->url, \TheNguyen\CMS\Models\Content::query()->value('featured_image'));
    }

    // ---- 14: rich-editor Insert Media snapshot on the same host forms

    public function test_14_rich_editor_media_snapshot_requires_media_view(): void
    {
        $this->actor(['media.upload']);
        $this->assertNoUnrelatedDisclosure(Livewire::test(CreatePost::class)->html());

        $this->actor(['media.view'], 'lib-viewer');
        $this->assertStringContainsString(self::SECRET_NAME, Livewire::test(CreatePost::class)->html());
    }
}
