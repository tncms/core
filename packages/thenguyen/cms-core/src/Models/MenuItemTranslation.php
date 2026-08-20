<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $menu_item_id
 * @property string $locale
 * @property string $title
 * @property string|null $url
 */
class MenuItemTranslation extends Model
{
    protected $table = 'cms_menu_item_translations';

    protected $fillable = [
        'menu_item_id',
        'locale',
        'title',
        'url',
    ];

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
