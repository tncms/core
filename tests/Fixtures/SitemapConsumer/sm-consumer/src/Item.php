<?php

declare(strict_types=1);

namespace Plugins\SmConsumer;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One public resource of the fixture plugin (article, topic, draw, product, category). */
final class Item extends Model
{
    use SoftDeletes;

    protected $table = 'smc_items';

    protected $guarded = [];

    protected $casts = ['draw_date' => 'date'];

    /** The plugin's own eligibility: published and not soft-deleted (scope). */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
