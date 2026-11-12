<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Filament\Admin\Pages\MediaUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;

/**
 * CORE-40-FRONTEND-MEDIA-R1 §6 — S-M1 (admin upload page) and S-M2 (admin media search).
 */
final class AdminMediaHardeningTest extends TestCase
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

    // ---- S-M1

    public function test_panel_user_without_media_upload_cannot_open_the_upload_page(): void
    {
        $user = $this->userWithPermissions(['admin.access']);

        $this->actingAs($user)->get('/admin/media/upload')->assertForbidden();
    }

    public function test_panel_user_without_media_upload_cannot_execute_the_upload_action(): void
    {
        $user = $this->userWithPermissions(['admin.access']);
        $this->actingAs($user);

        Livewire::test(MediaUpload::class)->assertForbidden();
        $this->assertFalse(MediaUpload::canAccess());
    }

    public function test_upload_permission_is_rechecked_when_files_are_saved(): void
    {
        $user = $this->userWithPermissions(['admin.access', 'media.upload'], 'uploader');
        $this->actingAs($user);
        $component = Livewire::test(MediaUpload::class)->assertOk();

        // Permission revoked after the page was opened (next Livewire request).
        \TheNguyen\CMS\Models\Role::query()->where('slug', 'uploader')->firstOrFail()->permissions()->detach();
        $this->flushPermissions();

        try {
            $component->set('data.files', [\Illuminate\Http\UploadedFile::fake()->image('late.jpg')])->call('save');
        } catch (\Throwable) {
            // The 403 aborts the Livewire update; what matters is that nothing was stored.
        }
        $this->assertSame(0, \TheNguyen\CMS\Models\Media::query()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_panel_user_with_media_upload_can_still_open_and_use_the_upload_page(): void
    {
        $user = $this->userWithRole('author'); // admin.access + media.upload

        $this->actingAs($user)->get('/admin/media/upload')->assertOk();
        $this->assertTrue(MediaUpload::canAccess());
    }

    // ---- S-M2

    public function test_media_search_requires_media_view(): void
    {
        $user = $this->userWithPermissions(['admin.access']);

        $this->actingAs($user)->getJson('/admin/cms-media-search?q=a')->assertForbidden();
    }

    public function test_media_search_contract_is_preserved_for_authorized_users(): void
    {
        $editor = $this->userWithRole('editor');
        $media = $this->mediaRow($editor, ['original_filename' => 'needle.jpg', 'filename' => 'needle.jpg', 'url' => '/uploads/2026/11/needle.jpg', 'path' => 'uploads/2026/11/needle.jpg']);

        $response = $this->actingAs($editor)->getJson('/admin/cms-media-search?q=needle&limit=5')->assertOk();

        $this->assertSame([$media->id], array_column($response->json('data'), 'id'));
    }
}
