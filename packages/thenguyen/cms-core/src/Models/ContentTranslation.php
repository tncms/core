<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $content_id
 * @property string $locale
 * @property string|null $title
 * @property string $slug
 * @property string|null $excerpt
 * @property string|null $content
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 */
class ContentTranslation extends Model
{
    protected $table = 'cms_content_translations';

    protected $fillable = [
        'content_id',
        'locale',
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

    /**
     * Model-level backstop (CORE-STRUCTURED-CONTENT-AUTHORITY-R1): a translation
     * of extension-managed content may only be written inside a ContentManager
     * write. Ordinary content is unaffected (one lookup only for writes made
     * outside the content service).
     */
    protected static function booted(): void
    {
        $guard = static function (self $translation): void {
            $contentId = (int) ($translation->getOriginal('content_id') ?? $translation->content_id);

            if ($contentId === 0 || app('cms.content.gate')->isSanctioned($contentId)) {
                return;
            }

            try {
                $owner = Content::withTrashed()->whereKey($contentId)->value('managed_by');
            } catch (\Illuminate\Database\QueryException) {
                return; // marker column not migrated yet: no content can be managed
            }

            if ($owner !== null) {
                throw new \TheNguyen\CMS\ContentAuthority\ContentWriteDenied(
                    'unsanctioned_write',
                    'This content is managed by an extension and can only be changed through the content service.',
                    $contentId,
                    (string) $owner,
                );
            }
        };

        static::saving($guard);
        static::deleting($guard);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class, 'content_id');
    }
}
