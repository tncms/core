<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $menu_id
 * @property string $locale
 * @property string $name
 * @property string|null $description
 */
class MenuTranslation extends Model
{
    protected $table = 'cms_menu_translations';

    protected $fillable = [
        'menu_id',
        'locale',
        'name',
        'description',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
