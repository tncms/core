<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \TheNguyen\CMS\Models\Media upload(\Illuminate\Http\UploadedFile $file, array $meta = [])
 * @method static bool delete(\TheNguyen\CMS\Models\Media $media)
 * @method static \TheNguyen\CMS\Models\Media|null find(int $id)
 * @method static \Illuminate\Database\Eloquent\Collection all()
 *
 * @see \TheNguyen\CMS\Services\MediaManager
 */
class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.media';
    }
}
