<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Models\Media;

/**
 * CORE-40-FRONTEND-MEDIA-R1 S-M3 — regression coverage of MediaManager::upload() validation,
 * which the admin library, picker, rich editor and the new frontend service all share.
 */
final class MediaManagerUploadValidationTest extends TestCase
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

    private function rejects($file, string $message): void
    {
        try {
            app('cms.media')->upload($file);
            $this->fail('upload should have been rejected');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['file'][0] ?? null);
        }
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }

    public function test_allowed_images_are_stored_with_dimensions(): void
    {
        foreach ([['a.jpg', 'jpeg'], ['b.png', 'png'], ['c.webp', 'webp'], ['d.gif', 'gif']] as [$name, $format]) {
            $media = app('cms.media')->upload($this->realImage($name, $format, 20, 10));
            $this->assertSame([20, 10], [$media->width, $media->height]);
        }
        $this->assertCount(4, $this->storedUploadFiles());
    }

    public function test_executable_extensions_are_denied(): void
    {
        $this->rejects($this->realFile('x.php', "<?php echo 1;\n"), 'This file type is not allowed.');
        $this->rejects($this->realFile('x.phtml', "<?php echo 1;\n"), 'This file type is not allowed.');
        $this->rejects($this->realFile('x.html', '<html></html>'), 'This file type is not allowed.');
    }

    public function test_extension_spoofing_is_caught_by_content_sniffing(): void
    {
        $this->rejects($this->realFile('shell.jpg', "<?php system('id'); ?>\n", 'image/jpeg'), 'The file content does not match an allowed type.');
        $this->rejects($this->realFile('page.png', '<html><body>x</body></html>', 'image/png'), 'The file content does not match an allowed type.');
    }

    public function test_svg_is_rejected_while_disabled(): void
    {
        $this->rejects($this->realFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'), 'SVG uploads are disabled.');
    }

    public function test_unlisted_extensions_are_rejected(): void
    {
        $this->rejects($this->realFile('archive.zip', "PK\x03\x04"), 'This file type is not allowed.');
    }

    public function test_size_limit_and_empty_files(): void
    {
        settings()->set('media.max_upload_size_mb', 1, 'integer');
        $big = $this->realImage('big.png', 'png', 8, 8);
        file_put_contents($big->getRealPath(), random_bytes(1024 * 1024 + 1), FILE_APPEND);
        clearstatcache();

        $this->rejects(new \Illuminate\Http\UploadedFile($big->getRealPath(), 'big.png', null, null, true), 'The file is larger than the allowed maximum.');
        $this->rejects($this->realFile('empty.png', ''), 'The file content does not match an allowed type.');
    }

    public function test_malicious_filenames_are_slugged_and_collisions_get_a_suffix(): void
    {
        $first = app('cms.media')->upload($this->realImage('../../Ảnh đẹp <script>.jpg'));
        $second = app('cms.media')->upload($this->realImage('../../Ảnh đẹp <script>.jpg'));

        $this->assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/anh-dep-script\.jpg$#', $first->path);
        $this->assertMatchesRegularExpression('#^uploads/\d{4}/\d{2}/anh-dep-script-1\.jpg$#', $second->path);
    }

    public function test_a_failed_record_write_inside_a_transaction_leaves_no_file(): void
    {
        Media::creating(static function (): void {
            throw new \RuntimeException('boom');
        });

        try {
            \Illuminate\Support\Facades\DB::transaction(fn () => app('cms.media')->upload($this->realImage('a.jpg')));
            $this->fail('expected failure');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], $this->storedUploadFiles());
    }
}
