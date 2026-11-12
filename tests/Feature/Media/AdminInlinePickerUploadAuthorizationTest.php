<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Filament\Admin\Components\MediaPicker;
use App\Filament\Admin\Pages\SettingsPage;
use App\Filament\Admin\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Admin\Resources\PostResource\Pages\CreatePost;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Role;

/**
 * CORE-40-FRONTEND-MEDIA-R1-H1 — the admin inline MediaPicker upload must require
 * media.upload on every request, independent of admin.access, page access and UI state.
 *
 * Every scenario drives the real Filament action (mount → fill → submit) on real
 * host pages through Livewire, with roles from CmsRolePermissionSeeder.
 */
final class AdminInlinePickerUploadAuthorizationTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();
        // The installer's core "category" taxonomy is hierarchical, which is what puts
        // the featured-image picker on the category form.
        \TheNguyen\CMS\Models\Taxonomy::query()->firstOrCreate(['slug' => 'category'], [
            'type' => 'category', 'content_type' => 'post', 'hierarchical' => true, 'is_core' => true, 'sort_order' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    /**
     * Host pages that embed the shared MediaPicker: [page class, picker field, permissions to open the page].
     *
     * @return array<string, array{0: class-string, 1: string, 2: list<string>}>
     */
    public static function hosts(): array
    {
        return [
            'post featured image' => [CreatePost::class, 'featured_image', ['posts.view', 'posts.create']],
            'category featured image' => [CreateCategory::class, 'featured_image', ['taxonomy.manage']],
            'settings favicon' => [SettingsPage::class, 'general_favicon', ['settings.manage']],
        ];
    }

    private function actor(array $hostPermissions, array $media, string $role = 'h1-actor'): \App\Models\User
    {
        $user = $this->userWithPermissions(array_merge(['admin.access'], $hostPermissions, $media), $role);
        $this->actingAs($user);

        return $user;
    }

    private function picker(string $page, string $field): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test($page)
            ->assertOk()
            ->mountAction(TestAction::make($field.'_select')->schemaComponent(true));
    }

    private function upload(\Livewire\Features\SupportTesting\Testable $lw, string $name = 'inline.jpg'): \Livewire\Features\SupportTesting\Testable
    {
        return $lw->fillForm(['uploaded_url' => [UploadedFile::fake()->image($name, 40, 30)]]);
    }

    /** Submit the mounted picker action; a 403 aborts the Livewire update, which is the denial. */
    private function submit(\Livewire\Features\SupportTesting\Testable $lw): ?int
    {
        try {
            $lw->callMountedAction();

            return null;
        } catch (HttpException $e) {
            return $e->getStatusCode();
        }
    }

    private function stateOf(\Livewire\Features\SupportTesting\Testable $lw, string $field): mixed
    {
        return $lw->get('data.'.$field);
    }

    private function assertNothingStored(int $rowsBefore = 0): void
    {
        $this->assertSame($rowsBefore, Media::query()->count(), 'no cms_media row may be created');
        $this->assertSame([], $this->storedUploadFiles(), 'no permanent upload file may be written');
        $this->assertSame([], $this->temporaryFiles(), 'the refused temporary upload is discarded');
    }

    /**
     * Livewire temporary-upload content files currently on the temporary disk.
     *
     * Livewire writes a small `<name>.json` metadata sidecar (client name, type,
     * size, hash; no file content) next to every temporary upload. Neither
     * Livewire's TemporaryUploadedFile::delete() nor Filament's own successful
     * save removes it; Livewire's 24-hour cleanupOldUploads() does. The sidecar is
     * therefore excluded here and documented in H1 file 03.
     *
     * @return list<string>
     */
    private function temporaryFiles(): array
    {
        return array_values(array_filter(
            FileUploadConfiguration::storage()->allFiles(FileUploadConfiguration::path()),
            static fn (string $path): bool => ! str_ends_with($path, '.json'),
        ));
    }

    private function existingImage(): Media
    {
        return $this->mediaRow(null, ['uploaded_by' => null, 'path' => 'uploads/2026/11/existing.jpg', 'url' => '/uploads/2026/11/existing.jpg']);
    }

    // 1 + 10 — view-only actor browses and selects existing media.
    #[DataProvider('hosts')]
    public function test_view_only_actor_can_browse_and_select_existing_media(string $page, string $field, array $perms): void
    {
        $existing = $this->existingImage();
        $this->actor($perms, ['media.view']);

        $lw = $this->picker($page, $field)->fillForm(['selected_url' => $existing->url]);
        $this->assertNull($this->submit($lw));

        $this->assertSame($existing->url, $this->stateOf($lw, $field));
        $this->assertSame(1, Media::query()->count());
        $this->get(route('filament.admin.cms-media-search', ['q' => 'existing']))->assertOk();
    }

    // 2, 8, 9 — same actor cannot upload inline.
    #[DataProvider('hosts')]
    public function test_view_only_actor_cannot_upload_inline(string $page, string $field, array $perms): void
    {
        $this->actor($perms, ['media.view']);

        $lw = $this->upload($this->picker($page, $field));
        $this->submit($lw);

        $this->assertNothingStored();
        $this->assertNotSame('/uploads/2026/11/inline.jpg', $this->stateOf($lw, $field));
        $this->assertFalse(str_starts_with((string) $this->stateOf($lw, $field), '/uploads/'));
    }

    // 10 — a denied upload leaves the previously selected value intact.
    public function test_a_denied_upload_keeps_the_existing_selected_value(): void
    {
        $existing = $this->existingImage();
        $this->actor(['posts.view', 'posts.create'], ['media.view']);

        $lw = Livewire::test(CreatePost::class)->set('data.featured_image', $existing->url);
        $lw->mountAction(TestAction::make('featured_image_select')->schemaComponent(true));
        $this->upload($lw);
        $this->submit($lw);

        $this->assertSame($existing->url, $this->stateOf($lw, 'featured_image'));
        $this->assertNothingStored(1);
    }

    // 3 — media.upload holder uploads normally (same path, positive control).
    #[DataProvider('hosts')]
    public function test_actor_with_media_upload_can_upload_inline(string $page, string $field, array $perms): void
    {
        $this->actor($perms, ['media.view', 'media.upload']);

        $lw = $this->upload($this->picker($page, $field), 'allowed.jpg');
        $this->assertNull($this->submit($lw));

        $this->assertSame(1, Media::query()->count());
        $this->assertCount(1, $this->storedUploadFiles());
        $this->assertSame(Media::query()->value('url'), $this->stateOf($lw, $field));
    }

    // 4 — revocation applies to the next Livewire request on an already-open modal.
    public function test_revoking_media_upload_denies_the_next_request(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view', 'media.upload']);
        $lw = $this->upload($this->picker(CreatePost::class, 'featured_image'));

        Role::query()->where('slug', 'h1-actor')->firstOrFail()->permissions()
            ->detach(\TheNguyen\CMS\Models\Permission::query()->where('slug', 'media.upload')->value('id'));
        $this->flushPermissions();

        $this->submit($lw);
        $this->assertNothingStored();

        // …and re-granting restores it.
        Role::query()->where('slug', 'h1-actor')->firstOrFail()->permissions()
            ->attach(\TheNguyen\CMS\Models\Permission::query()->where('slug', 'media.upload')->value('id'));
        $this->flushPermissions();
        $lw2 = $this->upload($this->picker(CreatePost::class, 'featured_image'), 'regranted.jpg');
        $this->assertNull($this->submit($lw2));
        $this->assertSame(1, Media::query()->count());
    }

    // 5 — forged Livewire request: the actor finishes a temporary upload straight into the
    // mounted action's state path through Livewire's public _finishUpload method, although
    // the Upload tab is not offered to them.
    public function test_a_forged_upload_into_the_action_state_is_denied(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view']);
        $signed = TemporaryUploadedFile::signPath($this->temporaryUpload('forged.jpg')->getFilename());

        $lw = $this->picker(CreatePost::class, 'featured_image');
        $this->finishUpload($lw, $signed);
        $this->submit($lw);

        $this->assertNothingStored();
    }

    // 6 — replay: a signed temporary-upload reference issued while the actor held
    // media.upload is re-submitted after revocation (Livewire signatures do not expire).
    public function test_a_replayed_temporary_upload_is_denied_after_revocation(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view', 'media.upload']);
        // Both references are signed while the actor is authorised. Filament deletes a
        // temporary file once it is stored, so the replayable window is a reference that
        // was issued but not yet submitted.
        $controlRef = TemporaryUploadedFile::signPath($this->temporaryUpload('control.jpg')->getFilename());
        $signed = TemporaryUploadedFile::signPath($this->temporaryUpload('replay.jpg')->getFilename());

        // Positive control: the same mechanism really stores while authorised.
        $control = $this->picker(CreatePost::class, 'featured_image');
        $this->finishUpload($control, $controlRef);
        $this->assertNull($this->submit($control));
        $this->assertSame(1, Media::query()->count(), 'control: an authorised replay stores');

        Role::query()->where('slug', 'h1-actor')->firstOrFail()->permissions()
            ->detach(\TheNguyen\CMS\Models\Permission::query()->where('slug', 'media.upload')->value('id'));
        $this->flushPermissions();

        $replay = $this->picker(CreatePost::class, 'featured_image');
        $this->finishUpload($replay, $signed);
        $this->submit($replay);

        $this->assertSame(1, Media::query()->count(), 'the replay after revocation stores nothing new');
        $this->assertCount(1, $this->storedUploadFiles());
        $this->assertSame([], $this->temporaryFiles(), 'the replayed temporary upload is discarded');
    }

    private function finishUpload(\Livewire\Features\SupportTesting\Testable $lw, string $signedPath): void
    {
        try {
            $lw->call('_finishUpload', 'mountedActions.0.data.uploaded_url', [$signedPath], true);
        } catch (HttpException) {
            // Refusing the temporary upload itself is also a denial.
        }
    }

    // 7 — frontend media.upload_own never authorises the admin inline upload.
    public function test_media_upload_own_alone_cannot_use_the_admin_inline_upload(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view', 'media.view_own', 'media.upload_own']);

        $lw = $this->upload($this->picker(CreatePost::class, 'featured_image'));
        $this->submit($lw);

        $this->assertNothingStored();
    }

    // Final boundary — the store callback itself refuses without media.upload and
    // removes only the temporary file it was handed.
    public function test_the_store_boundary_refuses_and_discards_the_temporary_file(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view']);

        $temporary = $this->temporaryUpload('boundary.jpg');
        $this->assertTrue($temporary->exists());

        try {
            (new \ReflectionMethod(MediaPicker::class, 'storeUploadedFile'))->invoke(null, $temporary);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertFalse($temporary->exists(), 'the refused temporary upload is discarded');
        $this->assertNothingStored();
    }

    public function test_the_store_boundary_stores_for_media_upload_holders(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view', 'media.upload']);

        $url = (new \ReflectionMethod(MediaPicker::class, 'storeUploadedFile'))->invoke(null, $this->temporaryUpload('ok.jpg'));

        $this->assertSame(Media::query()->value('url'), $url);
        $this->assertCount(1, $this->storedUploadFiles());
    }

    // The picker does not offer the upload tab to actors who cannot upload.
    public function test_upload_tab_is_only_offered_to_media_upload_holders(): void
    {
        $this->actor(['posts.view', 'posts.create'], ['media.view']);
        $this->picker(CreatePost::class, 'featured_image')
            ->assertFormFieldExists('selected_url')
            ->assertFormFieldHidden('uploaded_url');

        $this->actor(['posts.view', 'posts.create'], ['media.view', 'media.upload'], 'h1-uploader');
        $this->picker(CreatePost::class, 'featured_image')
            ->assertFormFieldExists('selected_url')
            ->assertFormFieldVisible('uploaded_url');
    }

    private function temporaryUpload(string $name): TemporaryUploadedFile
    {
        $source = UploadedFile::fake()->image($name, 40, 30);
        $stored = FileUploadConfiguration::storage()->putFileAs(
            FileUploadConfiguration::path(),
            $source,
            TemporaryUploadedFile::generateHashNameWithOriginalNameEmbedded($source),
        );

        return TemporaryUploadedFile::createFromLivewire(basename((string) $stored));
    }
}
