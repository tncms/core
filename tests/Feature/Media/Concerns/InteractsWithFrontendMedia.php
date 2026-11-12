<?php

declare(strict_types=1);

namespace Tests\Feature\Media\Concerns;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Models\Media;
use TheNguyen\CMS\Models\Permission;
use TheNguyen\CMS\Models\Role;

/**
 * Shared fixtures for the CORE-40-FRONTEND-MEDIA-R1 tests.
 *
 * - Uploads go to a per-test temporary public path, never the repository's public/uploads.
 * - RBAC is seeded with Core's own seeder, so fail-open (pre-initialised) behaviour never
 *   hides a missing grant.
 * - Image fixtures are real files (GD) wrapped in UploadedFile in test mode, so the MIME type
 *   is sniffed from the bytes (UploadedFile::fake() reports it from the extension instead).
 */
trait InteractsWithFrontendMedia
{
    protected string $mediaPublicPath = '';

    /** @var list<string> */
    private array $mediaFixtureFiles = [];

    protected function setUpFrontendMedia(): void
    {
        $this->mediaPublicPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'core40-media-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->mediaPublicPath);
        // The admin panel needs the Vite manifest under the public path.
        $manifest = base_path('public/build');
        if (is_dir($manifest)) {
            File::copyDirectory($manifest, $this->mediaPublicPath.DIRECTORY_SEPARATOR.'build');
        }
        $this->app->usePublicPath($this->mediaPublicPath);
        config(['cms.super_admin_emails' => []]);
        $this->seed(CmsRolePermissionSeeder::class);
    }

    protected function tearDownFrontendMedia(): void
    {
        foreach ($this->mediaFixtureFiles as $file) {
            @unlink($file);
        }
        if ($this->mediaPublicPath !== '' && str_contains($this->mediaPublicPath, 'core40-media-') && is_dir($this->mediaPublicPath)) {
            File::deleteDirectory($this->mediaPublicPath);
        }
    }

    protected function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', $roleSlug)->firstOrFail()->id]);
        $this->flushPermissions();

        return $user->fresh();
    }

    /** @param  list<string>  $permissions */
    protected function userWithPermissions(array $permissions, string $roleSlug = 'custom-media'): User
    {
        $role = Role::query()->firstOrCreate(['slug' => $roleSlug], ['name' => ucfirst($roleSlug)]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $permissions)->pluck('id')->all());
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $this->flushPermissions();

        return $user->fresh();
    }

    /** Simulates the next request: the permission manager memoises per request. */
    protected function flushPermissions(): void
    {
        app('cms.permission')->flushResolutionCache();
    }

    /** A real image file encoded by GD, wrapped as an upload whose MIME is sniffed from content. */
    protected function realImage(string $clientName, string $format = 'jpeg', int $width = 32, int $height = 24): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 200, 40, 40));
        $path = $this->fixturePath();
        match ($format) {
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path),
            'gif' => imagegif($image, $path),
            default => imagejpeg($image, $path),
        };
        imagedestroy($image);

        return new UploadedFile($path, $clientName, null, null, true);
    }

    /** Arbitrary bytes under any client name and client MIME. */
    protected function realFile(string $clientName, string $bytes, ?string $clientMime = null): UploadedFile
    {
        $path = $this->fixturePath();
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    /**
     * A tiny, valid-looking PNG whose IHDR declares huge dimensions (decompression-bomb shape)
     * with no pixel data. Safe: nothing ever decodes the pixels.
     */
    protected function craftedPng(string $clientName, int $width, int $height): UploadedFile
    {
        $ihdr = pack('N', $width).pack('N', $height)."\x08\x02\x00\x00\x00";
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IEND', '');

        return $this->realFile($clientName, $bytes, 'image/png');
    }

    /** A media row owned by $owner (no file needed for listing/resolution tests). */
    protected function mediaRow(?User $owner, array $overrides = []): Media
    {
        static $n = 0;
        $n++;

        return Media::query()->create(array_merge([
            'disk' => 'public',
            'folder' => 'uploads/2026/11',
            'filename' => "row-{$n}.jpg",
            'original_filename' => "row-{$n}.jpg",
            'extension' => 'jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1000,
            'width' => 10,
            'height' => 10,
            'path' => "uploads/2026/11/row-{$n}.jpg",
            'url' => "/uploads/2026/11/row-{$n}.jpg",
            'alt' => "Alt {$n}",
            'title' => "Title {$n}",
            'uploaded_by' => $owner?->id,
        ], $overrides));
    }

    /** Files currently under the temporary uploads directory. */
    protected function storedUploadFiles(): array
    {
        $dir = $this->mediaPublicPath.DIRECTORY_SEPARATOR.'uploads';
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_map(static fn ($f) => $f->getPathname(), File::allFiles($dir)));
    }

    private function fixturePath(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'core40-fx-'.bin2hex(random_bytes(6));
        $this->mediaFixtureFiles[] = $path;

        return $path;
    }
}
