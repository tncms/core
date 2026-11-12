<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Tests\Feature\Media\Concerns\InteractsWithFrontendMedia;
use Tests\TestCase;
use TheNguyen\CMS\Media\ImageMetadataSanitizer;
use TheNguyen\CMS\Models\Media;

/**
 * CORE-P12-1 — image upload security on the shared MediaManager::upload() path (admin library,
 * picker, rich editor and the frontend library all go through it): private staging, sniffed MIME
 * only, extension/MIME agreement, header pixel ceiling, lossless metadata stripping with the
 * orientation preserved, trailing-payload removal, fail-closed on unparseable images.
 */
final class MediaImageSecurityTest extends TestCase
{
    use InteractsWithFrontendMedia;
    use RefreshDatabase;

    private const SECRET = 'SECRETCAM-4471';

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

    private function upload(UploadedFile $file): Media
    {
        return app('cms.media')->upload($file);
    }

    private function storedBytes(Media $media): string
    {
        return (string) file_get_contents($this->mediaPublicPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $media->path));
    }

    private function assertRejected(UploadedFile $file, string $message): void
    {
        $before = $this->storedUploadFiles();
        try {
            $this->upload($file);
            $this->fail('expected the upload to be rejected');
        } catch (ValidationException $e) {
            $this->assertSame($message, $e->errors()['file'][0] ?? null);
        }
        $this->assertSame($before, $this->storedUploadFiles(), 'nothing may reach public/uploads');
        $this->assertSame(0, Media::query()->count());
        $this->assertStagingEmpty();
    }

    private function assertStagingEmpty(): void
    {
        $dir = storage_path('app/cms-media-staging');
        $this->assertSame([], is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : []);
    }

    private function gdBytes(string $format, int $w = 40, int $h = 20): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($image, 10, 120, 200));
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            'avif' => imageavif($image),
            default => imagejpeg($image),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** Little-endian TIFF: IFD0 {Make, Orientation, GPS pointer}, GPS IFD {GPSLatitudeRef}. */
    private function exifTiff(int $orientation): string
    {
        $make = self::SECRET."\0";
        $ifd0 = 8;
        $ifd0Size = 2 + 3 * 12 + 4;
        $makeAt = $ifd0 + $ifd0Size;
        $gpsAt = $makeAt + strlen($make);

        return "II\x2A\x00".pack('V', $ifd0)
            .pack('v', 3)
            .pack('vvVV', 0x010F, 2, strlen($make), $makeAt)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\x00\x00"
            .pack('vvVV', 0x8825, 4, 1, $gpsAt)
            .pack('V', 0)
            .$make
            .pack('v', 1).pack('vvV', 0x0001, 2, 2).'N'."\0\0\0".pack('V', 0);
    }

    private function jpegWithMetadata(int $orientation = 6): string
    {
        $jpeg = $this->gdBytes('jpeg');
        $app1 = "Exif\0\0".$this->exifTiff($orientation);
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta>".self::SECRET.'</x:xmpmeta>';
        $segments = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1
            ."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp
            ."\xFF\xED".pack('n', strlen('Photoshop 3.0 '.self::SECRET) + 2).'Photoshop 3.0 '.self::SECRET
            ."\xFF\xFE".pack('n', strlen(self::SECRET) + 2).self::SECRET;

        return "\xFF\xD8".$segments.substr($jpeg, 2).'<?php echo "'.self::SECRET.'"; ?>';
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    public function test_jpeg_exif_gps_xmp_iptc_comment_and_trailing_payload_are_removed_orientation_kept(): void
    {
        $file = $this->realFile('photo.jpg', $this->jpegWithMetadata(6), 'image/jpeg');
        $original = exif_read_data($file->getRealPath());
        $this->assertSame(self::SECRET, $original['Make'] ?? null, 'fixture carries the secret EXIF');
        $this->assertArrayHasKey('GPSLatitudeRef', $original);

        $media = $this->upload($file);
        $stored = $this->storedBytes($media);

        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('xmpmeta', $stored);
        $this->assertStringEndsWith("\xFF\xD9", $stored);
        $exif = @exif_read_data($this->mediaPublicPath.'/'.$media->path) ?: [];
        $this->assertSame(6, $exif['Orientation'] ?? null);
        $this->assertArrayNotHasKey('Make', $exif);
        $this->assertArrayNotHasKey('GPSLatitudeRef', $exif);
        $this->assertNotFalse(imagecreatefromstring($stored), 'pixels still decode');
        $this->assertSame([40, 20], [(int) $media->width, (int) $media->height]);
        $this->assertSame(strlen($stored), (int) $media->size);
        $this->assertStagingEmpty();
    }

    public function test_jpeg_without_orientation_gets_no_exif_block(): void
    {
        $media = $this->upload($this->realFile('p.jpg', $this->jpegWithMetadata(1), 'image/jpeg'));
        $this->assertStringNotContainsString('Exif', $this->storedBytes($media));
    }

    public function test_png_text_and_exif_chunks_and_trailing_data_are_removed(): void
    {
        $png = $this->gdBytes('png');
        $iend = strrpos($png, 'IEND') - 4;
        $bytes = substr($png, 0, $iend)
            .self::pngChunk('tEXt', 'Author'."\0".self::SECRET)
            .self::pngChunk('iTXt', 'XML:com.adobe.xmp'."\0\0\0\0\0".self::SECRET)
            .self::pngChunk('eXIf', $this->exifTiff(3))
            .substr($png, $iend).'PK'.self::SECRET;

        $stored = $this->storedBytes($this->upload($this->realFile('p.png', $bytes, 'image/png')));

        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringEndsWith('IEND'.pack('N', crc32('IEND')), $stored);
        $this->assertNotFalse(imagecreatefromstring($stored));
    }

    public function test_webp_exif_and_xmp_chunks_are_removed_and_flags_cleared(): void
    {
        $webp = $this->gdBytes('webp');
        $this->assertSame('RIFF', substr($webp, 0, 4));
        $vp8 = substr($webp, 12);
        $chunk = static fn (string $cc, string $data): string => $cc.pack('V', strlen($data)).$data.(strlen($data) % 2 ? "\0" : '');
        $vp8x = $chunk('VP8X', chr(0x0C)."\0\0\0".substr(pack('V', 39), 0, 3).substr(pack('V', 19), 0, 3));
        $body = 'WEBP'.$vp8x.$vp8.$chunk('EXIF', $this->exifTiff(6)).$chunk('XMP ', self::SECRET);
        $bytes = 'RIFF'.pack('V', strlen($body)).$body.'TRAILING'.self::SECRET;

        $stored = $this->storedBytes($this->upload($this->realFile('p.webp', $bytes, 'image/webp')));

        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertSame(0, ord($stored[20]) & 0x0C, 'VP8X EXIF/XMP flags cleared');
        $this->assertSame(strlen($stored) - 8, unpack('V', substr($stored, 4, 4))[1]);
        $this->assertNotFalse(imagecreatefromstring($stored));
    }

    public function test_gif_comment_and_xmp_application_extension_are_removed(): void
    {
        $gif = $this->gdBytes('gif');
        $comment = "\x21\xFE".chr(strlen(self::SECRET)).self::SECRET."\x00";
        $xmp = "\x21\xFF\x0BXMP DataXMP".chr(strlen(self::SECRET)).self::SECRET."\x00";
        $netscape = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $flags = ord($gif[10]);
        $headerEnd = 13 + (($flags & 0x80) ? 3 * (2 << ($flags & 0x07)) : 0);
        $bytes = substr($gif, 0, $headerEnd).$netscape.$comment.$xmp.substr($gif, $headerEnd).self::SECRET;

        $stored = $this->storedBytes($this->upload($this->realFile('p.gif', $bytes, 'image/gif')));

        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStringContainsString('NETSCAPE2.0', $stored, 'animation loop kept');
        $this->assertStringEndsWith("\x3B", $stored);
        $this->assertNotFalse(imagecreatefromstring($stored));
    }

    #[RequiresFunction('imageavif')]
    public function test_avif_without_metadata_is_kept_and_with_exif_is_refused(): void
    {
        $avif = $this->gdBytes('avif');
        if (@getimagesize('data://image/avif;base64,'.base64_encode($avif)) === false) {
            $this->markTestSkipped('getimagesize cannot read this AVIF build');
        }
        $this->assertSame($avif, $this->storedBytes($this->upload($this->realFile('ok.avif', $avif, 'image/avif'))));

        // Same file with an "Exif" item type inside the meta box.
        $at = strpos($avif, 'meta');
        $this->assertNotFalse($at);
        $patched = $avif;
        $infe = strpos($patched, 'av01', $at);
        $this->assertNotFalse($infe);
        $patched = substr_replace($patched, 'Exif', $infe, 4);
        if (! in_array(@getimagesize('data://image/avif;base64,'.base64_encode($patched))[2] ?? null, [IMAGETYPE_AVIF], true)) {
            $this->markTestSkipped('patched AVIF is not recognised by getimagesize');
        }
        Media::query()->forceDelete();
        $this->assertRejected($this->realFile('exif.avif', $patched, 'image/avif'), 'The image could not be processed safely and was rejected.');
    }

    public function test_pixel_ceiling_refuses_decompression_bomb_shapes_from_the_header(): void
    {
        $this->assertRejected($this->craftedPng('bomb.png', 30000, 30000), 'The image has too many pixels.');

        config(['cms.media.max_pixels' => 100]);
        $this->assertRejected($this->realImage('small.png', 'png', 20, 20), 'The image has too many pixels.');
        $this->assertSame(1, $this->upload($this->realImage('ok.png', 'png', 10, 10))->exists ? 1 : 0);
    }

    public function test_extension_and_content_must_agree_even_between_allowed_types(): void
    {
        $this->assertRejected($this->realFile('photo.jpg', $this->gdBytes('png'), 'image/jpeg'), 'The file content does not match its extension.');
        $this->assertRejected($this->realFile('doc.png', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'image/png'), 'The file content does not match its extension.');
    }

    public function test_client_declared_mime_is_never_used(): void
    {
        $this->assertRejected($this->realFile('x.jpg', '<?php system($_GET["c"]); ?>', 'image/jpeg'), 'The file content does not match an allowed type.');
    }

    public function test_unparseable_images_fail_closed(): void
    {
        $jpeg = $this->gdBytes('jpeg');
        $this->assertRejected($this->realFile('cut.jpg', substr($jpeg, 0, (int) (strlen($jpeg) * 0.6)), 'image/jpeg'), 'The image could not be processed safely and was rejected.');

        $png = $this->gdBytes('png');
        $this->assertRejected($this->realFile('noend.png', substr($png, 0, strrpos($png, 'IEND') - 4), 'image/png'), 'The image could not be processed safely and was rejected.');
    }

    public function test_sanitizer_is_idempotent_and_rejects_unknown_types(): void
    {
        $sanitizer = new ImageMetadataSanitizer;
        $once = $sanitizer->sanitize($this->jpegWithMetadata(8), 'image/jpeg');
        $this->assertNotNull($once);
        $this->assertSame($once, $sanitizer->sanitize($once, 'image/jpeg'));
        $this->assertNull($sanitizer->sanitize('GIF89a', 'image/gif'));
        $this->assertNull($sanitizer->sanitize('anything', 'application/pdf'));
    }

    public function test_frontend_upload_goes_through_the_same_cleaning(): void
    {
        $author = $this->userWithRole('author');
        $summary = app('cms.media.frontend')->uploadOwn($author, $this->realFile('mine.jpg', $this->jpegWithMetadata(6), 'image/jpeg'));

        $stored = $this->storedBytes(Media::query()->findOrFail($summary->id));
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertStagingEmpty();
    }

    public function test_a_lost_filename_race_never_overwrites_another_upload(): void
    {
        // Simulate the race: the existence check misses a file that another request already wrote.
        $dir = $this->mediaPublicPath.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.date('Y').DIRECTORY_SEPARATOR.date('m');
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'race.jpg', 'OTHER-USERS-BYTES');
        File::partialMock()->shouldReceive('exists')->andReturn(false);

        $media = $this->upload($this->realImage('race.jpg'));

        File::swap(new \Illuminate\Filesystem\Filesystem);
        $this->assertSame('OTHER-USERS-BYTES', file_get_contents($dir.DIRECTORY_SEPARATOR.'race.jpg'));
        $this->assertNotSame('race.jpg', $media->filename);
        $this->assertMatchesRegularExpression('#^race-[a-z0-9]{6}\.jpg$#', (string) $media->filename);
        $this->assertNotFalse(imagecreatefromstring($this->storedBytes($media)));
        $this->assertStagingEmpty();
    }

    public function test_svg_is_still_sanitized_before_reaching_the_web_root(): void
    {
        config(['cms.media.allow_svg' => true, 'cms.media.allowed_extensions' => array_merge(config('cms.media.allowed_extensions'), ['svg'])]);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><script>alert(1)</script><rect width="4" height="4"/></svg>';

        $media = $this->upload($this->realFile('icon.svg', $svg, 'image/svg+xml'));

        $this->assertStringNotContainsString('<script', $this->storedBytes($media));
        $this->assertStagingEmpty();
        File::delete($this->mediaPublicPath.'/'.$media->path);
    }
}
