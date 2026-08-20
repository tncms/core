<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $reference_type
 * @property int $reference_id
 * @property string $locale
 * @property string $slug
 * @property string|null $prefix
 * @property string $full_path
 * @property bool $is_primary
 */
class Slug extends Model
{
    protected $table = 'cms_slugs';

    protected $fillable = [
        'reference_type',
        'reference_id',
        'locale',
        'slug',
        'prefix',
        'full_path',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];
}
