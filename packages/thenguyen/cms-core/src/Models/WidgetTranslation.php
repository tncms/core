<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $widget_id
 * @property string $locale
 * @property string|null $title
 * @property array<string, mixed>|null $settings
 */
class WidgetTranslation extends Model
{
    protected $table = 'cms_widget_translations';

    protected $fillable = [
        'widget_id',
        'locale',
        'title',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function widget(): BelongsTo
    {
        return $this->belongsTo(Widget::class, 'widget_id');
    }
}
