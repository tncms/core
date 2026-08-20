<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $content_id
 * @property int $term_id
 */
class ContentTerm extends Model
{
    protected $table = 'cms_content_terms';

    protected $fillable = [
        'content_id',
        'term_id',
    ];
}
