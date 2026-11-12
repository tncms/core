<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;

/**
 * CORE-P12-1: MediaManager::delete() lifecycle. The row is the source of truth and is
 * soft-deleted first; the file is removed only after the deletion is durable, and only when
 * it is a regular file inside public/uploads that no live row still references.
 */
final class MediaDeletionLifecycleTest extends TestCase
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

    private function storedMedia(string $name = 'photo.jpg'): Media
    {
        $media = app('cms.media')->upload($this->realImage($name), ['uploaded_by' => null]);
        $this->assertFileExists(public_path($media->path));

        return $media;
    }

    public function test_delete_soft_deletes_the_row_then_removes_the_file(): void
    {
        $media = $this->storedMedia();

        $this->assertTrue(app('cms.media')->delete($media));

        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileDoesNotExist(public_path($media->path));
    }

    public function test_a_cancelled_row_deletion_keeps_the_file(): void
    {
        $media = $this->storedMedia();
        Media::deleting(static fn (): bool => false);

        $this->assertFalse(app('cms.media')->delete($media));

        $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists(public_path($media->path), 'the live row must still point at an existing file');
    }

    public function test_a_failed_row_deletion_keeps_the_file(): void
    {
        $media = $this->storedMedia();
        Media::deleting(static function (): void {
            throw new \RuntimeException('database unavailable');
        });

        try {
            app('cms.media')->delete($media);
            $this->fail('expected the failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('database unavailable', $e->getMessage());
        }

        $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists(public_path($media->path));
    }

    public function test_the_file_waits_for_the_outer_transaction_and_survives_its_rollback(): void
    {
        $media = $this->storedMedia();

        try {
            \DB::transaction(function () use ($media): void {
                app('cms.media')->delete($media);
                $this->assertFileExists(public_path($media->path), 'file removal must wait for COMMIT');

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNotSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists(public_path($media->path));
    }

    public function test_an_already_trashed_row_only_has_its_file_removed_and_events_fire_once(): void
    {
        $media = $this->storedMedia();
        $events = 0;
        Media::deleted(static function () use (&$events): void {
            $events++;
        });

        // Filament's DeleteAction soft-deletes first, then calls the manager in after().
        $media->delete();
        $this->assertTrue(app('cms.media')->delete($media));

        $this->assertSame(1, $events);
        $this->assertFileDoesNotExist(public_path($media->path));
    }

    public function test_a_file_still_referenced_by_a_live_row_is_kept(): void
    {
        $media = $this->storedMedia();
        $twin = Media::query()->create($media->only(['disk', 'folder', 'filename', 'original_filename', 'extension', 'mime_type', 'size', 'width', 'height', 'path', 'url']));

        app('cms.media')->delete($media);

        $this->assertFileExists(public_path($twin->path));
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'traversal' => ['uploads/../.env'];
        yield 'outside uploads' => ['build/manifest.json'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'windows absolute' => ['C:\\Windows\\win.ini'];
        yield 'backslash traversal' => ['uploads\\..\\.env'];
        yield 'directory' => ['uploads/2026'];
        yield 'nul byte' => ["uploads/a.jpg\0.php"];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_never_delete_anything(string $path): void
    {
        File::ensureDirectoryExists(public_path('uploads/2026'));
        file_put_contents(public_path('.env'), 'SECRET');
        File::ensureDirectoryExists(public_path('build'));
        file_put_contents(public_path('build/manifest.json'), '{}');
        $media = $this->mediaRow(null, ['path' => $path, 'url' => '/'.$path]);

        $this->assertTrue(app('cms.media')->delete($media));

        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists(public_path('.env'));
        $this->assertFileExists(public_path('build/manifest.json'));
        $this->assertDirectoryExists(public_path('uploads/2026'));
    }

    public function test_a_failed_file_removal_keeps_the_deletion_and_is_reconciled_later(): void
    {
        $media = $this->storedMedia();
        $file = public_path($media->path);
        File::partialMock()->shouldReceive('delete')->andReturnFalse();

        $this->assertTrue(app('cms.media')->delete($media), 'the row deletion is durable even when the file stays');
        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
        $this->assertFileExists($file);

        File::swap(new \Illuminate\Filesystem\Filesystem);

        $this->artisan('cms:media:purge-deleted-files')->assertSuccessful();
        $this->assertFileDoesNotExist($file);
        $this->assertSoftDeleted('cms_media', ['id' => $media->id]);
    }

    public function test_purge_dry_run_reports_without_deleting_and_skips_live_rows(): void
    {
        $gone = $this->storedMedia('gone.jpg');
        $gone->delete();                          // trashed row, file left behind (pre-P12 data)
        $live = $this->storedMedia('live.jpg');

        $this->artisan('cms:media:purge-deleted-files', ['--dry-run' => true])
            ->expectsOutputToContain('1 file')
            ->assertSuccessful();
        $this->assertFileExists(public_path($gone->path));

        $this->artisan('cms:media:purge-deleted-files')->assertSuccessful();
        $this->assertFileDoesNotExist(public_path($gone->path));
        $this->assertFileExists(public_path($live->path));
    }
}
