<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Support\Hooks\HookContext;

/**
 * CORE-POST40-MAINTENANCE-P0-R1 / P0-06: MediaManager::importFile() (bundled/demo assets) created a library row
 * without firing `cms.media.uploaded`, so listeners that react to new media never saw imported files. It now fires
 * the same hook with the same payload as upload(), and a failed row write leaves no orphan file behind.
 */
final class MediaImportHookTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontendMedia();

        $this->source = storage_path('framework/testing/p0-06-'.bin2hex(random_bytes(4)).'.jpg');
        File::ensureDirectoryExists(dirname($this->source));
        File::copy($this->realImage('bundled.jpg')->getRealPath(), $this->source);
    }

    protected function tearDown(): void
    {
        File::delete($this->source);
        $this->tearDownFrontendMedia();
        parent::tearDown();
    }

    public function test_import_fires_the_uploaded_hook_once_with_the_stored_row(): void
    {
        $seen = [];
        add_action('cms.media.uploaded', static function (Media $media, HookContext $context) use (&$seen): void {
            $seen[] = [$media->id, $context->get('media')?->id];
        }, 10, 2);

        $media = app('cms.media')->importFile($this->source, ['alt' => 'Bundled']);

        $this->assertSame([[$media->id, $media->id]], $seen);
        $this->assertFileExists(public_path($media->path));
    }

    public function test_a_failed_row_write_leaves_no_orphan_import_file(): void
    {
        Media::creating(static function (): void {
            throw new \RuntimeException('row write failed');
        });

        try {
            app('cms.media')->importFile($this->source);
            $this->fail('the row write failure must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('row write failed', $e->getMessage());
        }

        $this->assertSame([], $this->storedUploadFiles(), 'no copied file may stay in the public web root');
        $this->assertSame(0, Media::query()->count());
    }
}
