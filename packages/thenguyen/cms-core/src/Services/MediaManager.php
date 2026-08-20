<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use TheNguyen\CMS\Models\Media;

class MediaManager
{
    /**
     * Image extensions for which we attempt to read pixel dimensions.
     */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

    /**
     * Store an uploaded file under public/uploads/YYYY/MM and record it.
     *
     * @param  array{alt?: string|null, title?: string|null, caption?: string|null, description?: string|null, uploaded_by?: int|null}  $meta
     */
    public function upload(UploadedFile $file, array $meta = []): Media
    {
        // Capture metadata from the client-provided file (not Livewire's temp name).
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? ''));
        $mime = $file->getMimeType() ?: $file->getClientMimeType();
        $size = (int) ($file->getSize() ?: 0);

        // Server-side gate. Filament's acceptedFileTypes() is a client hint only
        // and is trivially bypassed, so every upload — from the media library,
        // the media picker or the rich editor — is validated here before any
        // bytes are written to the public web root.
        $this->guardUpload($extension, $mime, $size);

        // Hook point: a media file passed validation and is about to be written
        // (v1.0.0-beta.7.1.12.2). A listener may observe or reject by throwing.
        do_action('cms.media.uploading', $file, hook_context(['file' => $file, 'meta' => $meta]));

        // Relative folder always uses forward slashes (used in path/url). The
        // YYYY/MM split is controlled by media.organize_uploads_by_date.
        $folder = settings('media.organize_uploads_by_date', true)
            ? 'uploads/'.date('Y').'/'.date('m')
            : 'uploads';
        $directory = public_path($folder);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $filename = $this->uniqueFilename($directory, $originalName, $extension);

        // Filesystem path is normalized for the OS; copy instead of move so we
        // never hit Livewire's "could not move TemporaryUploadedFile" failure
        // when temp and public dirs differ (and across Windows path separators).
        $fullPath = $directory.DIRECTORY_SEPARATOR.$filename;
        $source = $file->getRealPath();

        if ($source === false || ! File::copy($source, $fullPath)) {
            throw new \RuntimeException("Could not copy uploaded file to {$fullPath}");
        }

        // SVG is active content. guardUpload() already confirmed it is opt-in
        // allowed; now sanitize the bytes on disk before the file can be served.
        // If sanitization fails the file is unsafe — delete it and reject.
        if ($extension === 'svg' && ! (new SvgSanitizer())->sanitizeFile($fullPath)) {
            File::delete($fullPath);

            throw ValidationException::withMessages([
                'file' => __('The SVG file could not be sanitized and was rejected.'),
            ]);
        }

        // Read pixel dimensions from the final file, after the copy succeeds.
        [$width, $height] = $this->dimensions($fullPath, $extension);

        // Stored relative URL/path use forward slashes regardless of OS.
        $path = $folder.'/'.$filename;

        $humanized = Media::humanizeFilename($originalName);

        // Default the alt text from the (humanized) original filename when the
        // uploader did not supply one and media.auto_alt_from_filename is on.
        // User-provided alt/title/caption/description are never overwritten.
        $alt = $meta['alt'] ?? null;
        if ((! is_string($alt) || trim($alt) === '') && settings('media.auto_alt_from_filename', true)) {
            $alt = $humanized !== '' ? $humanized : null;
        }

        // Optionally default the title from the filename too (off by default).
        $title = $meta['title'] ?? null;
        if ((! is_string($title) || trim($title) === '') && settings('media.auto_title_from_filename', false)) {
            $title = $humanized !== '' ? $humanized : null;
        }

        $media = Media::create([
            'disk' => 'public',
            'folder' => $folder,
            'filename' => $filename,
            'original_filename' => $originalName,
            'extension' => $extension !== '' ? $extension : null,
            'mime_type' => $mime ?: null,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'path' => $path,
            'url' => '/'.$path,
            'alt' => $alt,
            'title' => $title,
            'caption' => $meta['caption'] ?? null,
            'description' => $meta['description'] ?? null,
            'uploaded_by' => $meta['uploaded_by'] ?? Auth::id(),
        ]);

        // Hook point: a media file was uploaded (v1.0.0-beta.7.1.11).
        do_action('cms.media.uploaded', $media, hook_context(['media' => $media]));

        return $media;
    }

    /**
     * Validate an upload against the server-side policy in config('cms.media').
     * Throws a ValidationException (surfaced inline by Filament/Livewire) on any
     * violation. The order is deliberate: the hard deny-list wins first, then the
     * SVG opt-in gate, then the extension and real-MIME allow-lists, then size.
     *
     * @throws ValidationException
     */
    private function guardUpload(string $extension, ?string $mime, int $size): void
    {
        $extension = strtolower($extension);
        $mime = strtolower((string) $mime);

        $denied = array_map('strtolower', (array) config('cms.media.denied_extensions', []));
        $allowedExt = array_map('strtolower', (array) config('cms.media.allowed_extensions', []));
        $allowedMimes = array_map('strtolower', (array) config('cms.media.allowed_mimes', []));

        $reject = static function (string $message): void {
            throw ValidationException::withMessages(['file' => $message]);
        };

        if ($extension === '') {
            $reject(__('The file has no extension and was rejected.'));
        }

        // Hard block: executable / scriptable types are never allowed, even if an
        // operator mistakenly adds one to allowed_extensions.
        if (in_array($extension, $denied, true)) {
            $reject(__('This file type is not allowed.'));
        }

        if ($extension === 'svg') {
            // SVG is opt-in and only when explicitly whitelisted.
            if (! config('cms.media.allow_svg', false) || ! in_array('svg', $allowedExt, true)) {
                $reject(__('SVG uploads are disabled.'));
            }

            if ($mime !== '' && ! in_array($mime, ['image/svg+xml', 'image/svg', 'text/xml', 'application/xml'], true)) {
                $reject(__('This file type is not allowed.'));
            }
        } else {
            if (! in_array($extension, $allowedExt, true)) {
                $reject(__('This file type is not allowed.'));
            }

            // Real MIME (from finfo) must match the allow-list — defeats content
            // disguised behind a permitted extension (e.g. PHP renamed to .jpg).
            if (! in_array($mime, $allowedMimes, true)) {
                $reject(__('The file content does not match an allowed type.'));
            }
        }

        $max = $this->maxUploadBytes();

        if ($size <= 0) {
            $reject(__('The uploaded file is empty.'));
        }

        if ($size > $max) {
            $reject(__('The file is larger than the allowed maximum.'));
        }
    }

    /**
     * The effective upload ceiling in bytes: the smaller of the hard config cap
     * and the operator-facing media.max_upload_size_mb setting.
     */
    private function maxUploadBytes(): int
    {
        $configMax = (int) config('cms.media.max_upload_size', 8 * 1024 * 1024);
        $settingMb = (int) settings('media.max_upload_size_mb', 8);
        $settingMax = ($settingMb >= 1 ? $settingMb : 8) * 1024 * 1024;

        $effective = min($configMax > 0 ? $configMax : $settingMax, $settingMax);

        return $effective > 0 ? $effective : 8 * 1024 * 1024;
    }

    /**
     * Import an already-on-disk file (e.g. a bundled demo asset) into the media
     * library: copy it under public/uploads using the same conventions as
     * upload(), then create a cms_media row. Unlike upload() this does not take a
     * Livewire UploadedFile — the caller passes an absolute source path it has
     * already validated. Dimensions in $meta win over auto-detection (needed for
     * SVG, which getimagesize() cannot read).
     *
     * @param  array{original_name?: string, mime?: string|null, alt?: string|null, title?: string|null, width?: int|null, height?: int|null, uploaded_by?: int|null}  $meta
     */
    public function importFile(string $absolutePath, array $meta = []): Media
    {
        if (! File::exists($absolutePath)) {
            throw new \RuntimeException("Source media file not found: {$absolutePath}");
        }

        $originalName = $meta['original_name'] ?? basename($absolutePath);
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        $folder = settings('media.organize_uploads_by_date', true)
            ? 'uploads/'.date('Y').'/'.date('m')
            : 'uploads';
        $directory = public_path($folder);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true, true);
        }

        $filename = $this->uniqueFilename($directory, $originalName, $extension);
        $fullPath = $directory.DIRECTORY_SEPARATOR.$filename;

        if (! File::copy($absolutePath, $fullPath)) {
            throw new \RuntimeException("Could not copy media file to {$fullPath}");
        }

        [$detectedWidth, $detectedHeight] = $this->dimensions($fullPath, $extension);
        $width = $meta['width'] ?? $detectedWidth;
        $height = $meta['height'] ?? $detectedHeight;

        $path = $folder.'/'.$filename;

        return Media::create([
            'disk' => 'public',
            'folder' => $folder,
            'filename' => $filename,
            'original_filename' => $originalName,
            'extension' => $extension !== '' ? $extension : null,
            'mime_type' => $meta['mime'] ?? null,
            'size' => (int) (File::size($fullPath) ?: 0),
            'width' => $width,
            'height' => $height,
            'path' => $path,
            'url' => '/'.$path,
            'alt' => $meta['alt'] ?? null,
            'title' => $meta['title'] ?? null,
            'caption' => null,
            'description' => null,
            'uploaded_by' => $meta['uploaded_by'] ?? Auth::id(),
        ]);
    }

    /**
     * Delete the physical file and soft delete the record.
     */
    public function delete(Media $media): bool
    {
        $fullPath = public_path($media->path);

        if (File::exists($fullPath)) {
            File::delete($fullPath);
        }

        return (bool) $media->delete();
    }

    public function find(int $id): ?Media
    {
        return Media::find($id);
    }

    /**
     * Batch-load media rows by id, keyed by id. Used by the section resolver to
     * collapse the per-image N+1 (one query per referenced media) into a single
     * whereIn lookup. Unknown ids are simply absent from the result.
     *
     * @param  array<int|string>  $ids
     * @return array<int, Media>
     */
    public function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        return Media::whereIn('id', $ids)->get()->keyBy('id')->all();
    }

    /**
     * Find a media row by its stored URL, or null when the URL is empty or no
     * row matches (e.g. an external URL). Used to normalize layout-editor media
     * fields (a picked URL) back to a canonical media id.
     */
    public function findByUrl(string $url): ?Media
    {
        if ($url === '') {
            return null;
        }

        return Media::query()->where('url', $url)->first();
    }

    /**
     * @return Collection<int, Media>
     */
    public function all(): Collection
    {
        return Media::query()->orderByDesc('created_at')->get();
    }

    /**
     * Build a collision-free filename from a SEO-friendly safe base name.
     *
     * On collision, append an incrementing numeric suffix:
     * image.jpg, image-1.jpg, image-2.jpg ...
     */
    private function uniqueFilename(string $directory, string $originalName, string $extension): string
    {
        $filename = $this->makeSafeFilename($originalName, $extension);

        if (! File::exists($directory.DIRECTORY_SEPARATOR.$filename)) {
            return $filename;
        }

        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $suffix = $ext !== '' ? '.'.$ext : '';

        $counter = 1;

        do {
            $filename = $base.'-'.$counter.$suffix;
            $counter++;
        } while (File::exists($directory.DIRECTORY_SEPARATOR.$filename));

        return $filename;
    }

    /**
     * Produce a SEO-friendly filename (base + lowercase extension).
     *
     * Latin/Vietnamese names are transliterated and slugged; names containing
     * non-Latin scripts (CJK, Arabic, Hindi, Thai, ...) get a random safe name.
     */
    protected function makeSafeFilename(string $originalName, string $extension): string
    {
        $ext = strtolower($extension);
        $suffix = $ext !== '' ? '.'.$ext : '';
        $name = pathinfo($originalName, PATHINFO_FILENAME);

        if ($this->containsSpecialNonLatinCharacters($name)) {
            return $this->randomFilename($suffix);
        }

        $base = Str::slug($this->vietnameseToAscii($name));

        if ($base === '') {
            return $this->randomFilename($suffix);
        }

        return $base.$suffix;
    }

    /**
     * Fallback name for non-Latin or empty input: media-{date}-{random}.{ext}.
     */
    private function randomFilename(string $suffix): string
    {
        return 'media-'.date('Ymd').'-'.Str::lower(Str::random(6)).$suffix;
    }

    /**
     * Detect characters from scripts we must not transliterate, so they fall
     * back to a random safe filename instead of being mangled.
     */
    protected function containsSpecialNonLatinCharacters(string $name): bool
    {
        $ranges = [
            '\x{4E00}-\x{9FFF}',   // CJK Unified Ideographs (Chinese, Kanji)
            '\x{3040}-\x{309F}',   // Hiragana
            '\x{30A0}-\x{30FF}',   // Katakana
            '\x{AC00}-\x{D7AF}',   // Hangul (Korean)
            '\x{0600}-\x{06FF}',   // Arabic
            '\x{0900}-\x{097F}',   // Devanagari (Hindi)
            '\x{0E00}-\x{0E7F}',   // Thai
            '\x{0400}-\x{04FF}',   // Cyrillic
            '\x{0590}-\x{05FF}',   // Hebrew
        ];

        return preg_match('/['.implode('', $ranges).']/u', $name) === 1;
    }

    /**
     * Strip Vietnamese accents and map Đ/đ to D/d. Other Latin diacritics are
     * handled afterwards by Str::slug's transliteration.
     */
    protected function vietnameseToAscii(string $name): string
    {
        $map = [
            'a' => ['à', 'á', 'ạ', 'ả', 'ã', 'â', 'ầ', 'ấ', 'ậ', 'ẩ', 'ẫ', 'ă', 'ằ', 'ắ', 'ặ', 'ẳ', 'ẵ'],
            'e' => ['è', 'é', 'ẹ', 'ẻ', 'ẽ', 'ê', 'ề', 'ế', 'ệ', 'ể', 'ễ'],
            'i' => ['ì', 'í', 'ị', 'ỉ', 'ĩ'],
            'o' => ['ò', 'ó', 'ọ', 'ỏ', 'õ', 'ô', 'ồ', 'ố', 'ộ', 'ổ', 'ỗ', 'ơ', 'ờ', 'ớ', 'ợ', 'ở', 'ỡ'],
            'u' => ['ù', 'ú', 'ụ', 'ủ', 'ũ', 'ư', 'ừ', 'ứ', 'ự', 'ử', 'ữ'],
            'y' => ['ỳ', 'ý', 'ỵ', 'ỷ', 'ỹ'],
            'd' => ['đ'],
            'A' => ['À', 'Á', 'Ạ', 'Ả', 'Ã', 'Â', 'Ầ', 'Ấ', 'Ậ', 'Ẩ', 'Ẫ', 'Ă', 'Ằ', 'Ắ', 'Ặ', 'Ẳ', 'Ẵ'],
            'E' => ['È', 'É', 'Ẹ', 'Ẻ', 'Ẽ', 'Ê', 'Ề', 'Ế', 'Ệ', 'Ể', 'Ễ'],
            'I' => ['Ì', 'Í', 'Ị', 'Ỉ', 'Ĩ'],
            'O' => ['Ò', 'Ó', 'Ọ', 'Ỏ', 'Õ', 'Ô', 'Ồ', 'Ố', 'Ộ', 'Ổ', 'Ỗ', 'Ơ', 'Ờ', 'Ớ', 'Ợ', 'Ở', 'Ỡ'],
            'U' => ['Ù', 'Ú', 'Ụ', 'Ủ', 'Ũ', 'Ư', 'Ừ', 'Ứ', 'Ự', 'Ử', 'Ữ'],
            'Y' => ['Ỳ', 'Ý', 'Ỵ', 'Ỷ', 'Ỹ'],
            'D' => ['Đ'],
        ];

        foreach ($map as $ascii => $accents) {
            $name = str_replace($accents, $ascii, $name);
        }

        return $name;
    }

    /**
     * Read width/height for raster images using PHP's native getimagesize().
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function dimensions(string $fullPath, string $extension): array
    {
        if (! in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return [null, null];
        }

        // getimagesize() cannot read SVG (vector); skip it safely.
        if ($extension === 'svg') {
            return [null, null];
        }

        $info = @getimagesize($fullPath);

        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
