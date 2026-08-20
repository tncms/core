<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $disk
 * @property string|null $folder
 * @property string $filename
 * @property string $original_filename
 * @property string|null $extension
 * @property string|null $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property string $path
 * @property string $url
 * @property string|null $alt
 * @property string|null $title
 * @property string|null $caption
 * @property string|null $description
 * @property int|null $uploaded_by
 */
class Media extends Model
{
    use SoftDeletes;

    protected $table = 'cms_media';

    protected $fillable = [
        'disk',
        'folder',
        'filename',
        'original_filename',
        'extension',
        'mime_type',
        'size',
        'width',
        'height',
        'path',
        'url',
        'alt',
        'title',
        'caption',
        'description',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    /**
     * Fire media delete lifecycle actions (v1.0.0-beta.7.1.12.2). Bound to the
     * model's Eloquent events so EVERY delete path emits the hooks exactly once.
     * Best-effort: a broken listener never blocks a delete.
     */
    protected static function booted(): void
    {
        static::deleting(static function (self $media): void {
            self::fireMediaLifecycle('cms.media.deleting', $media);
        });

        static::deleted(static function (self $media): void {
            self::fireMediaLifecycle('cms.media.deleted', $media);
        });
    }

    private static function fireMediaLifecycle(string $hook, self $media): void
    {
        try {
            if (function_exists('do_action') && function_exists('hook_context')) {
                do_action($hook, $media, hook_context(['media' => $media]));
            }
        } catch (\Throwable) {
            // Lifecycle notification must never break the delete itself.
        }
    }

    /**
     * Whether this file is an image (mime type starts with "image/").
     */
    public function isImage(): bool
    {
        return is_string($this->mime_type) && str_starts_with($this->mime_type, 'image/');
    }

    /**
     * Human-readable file size, e.g. 1024 => "1 KB", 1048576 => "1 MB".
     */
    public function humanSize(): string
    {
        $bytes = (int) $this->size;

        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / (1024 ** $power);
        $formatted = $power === 0 ? (string) $value : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted . ' ' . $units[$power];
    }

    /**
     * URL used to preview/serve the file.
     */
    public function previewUrl(): string
    {
        return (string) $this->url;
    }

    /**
     * Best alt text for SEO/accessibility. Never empty:
     * alt → title → humanized original filename → humanized stored filename.
     */
    public function seoAlt(): string
    {
        foreach ([$this->alt, $this->title] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach ([$this->original_filename, $this->filename] as $name) {
            $humanized = self::humanizeFilename($name);

            if ($humanized !== '') {
                return $humanized;
            }
        }

        return '';
    }

    /**
     * Title attribute for SEO: title → alt → null.
     */
    public function seoTitle(): ?string
    {
        foreach ([$this->title, $this->alt] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Caption for figure output, or null when not set.
     */
    public function seoCaption(): ?string
    {
        return is_string($this->caption) && trim($this->caption) !== ''
            ? trim($this->caption)
            : null;
    }

    /**
     * Description for the media library: description → caption → null.
     */
    public function seoDescription(): ?string
    {
        foreach ([$this->description, $this->caption] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Turn a filename into a readable label: strip the extension, replace
     * separators with spaces, collapse whitespace, and upper-case the first
     * letter. Does NOT restore Vietnamese accents — slugged names stay ASCII.
     */
    public static function humanizeFilename(?string $name): string
    {
        if (! is_string($name) || $name === '') {
            return '';
        }

        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = str_replace(['-', '_', '.'], ' ', $base);
        $base = trim((string) preg_replace('/\s+/', ' ', $base));

        return $base === '' ? '' : Str::ucfirst($base);
    }
}
