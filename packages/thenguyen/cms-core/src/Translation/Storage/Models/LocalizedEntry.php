<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored locale value for a translation key (Phase 8.1).
 *
 * Row identity is (namespace, key, locale) — enforced unique at the schema level,
 * which is the storage-side duplicate-locale guard. `namespace` is stored as ''
 * (never null) so the unique index behaves consistently across databases. This is
 * an internal persistence model; modules never touch it directly — they go through
 * the repository / storage driver.
 *
 * @property string $namespace
 * @property string $key
 * @property string $locale
 * @property string|null $value
 */
final class LocalizedEntry extends Model
{
    protected $table = 'cms_localized_values';

    protected $fillable = [
        'namespace',
        'key',
        'locale',
        'value',
    ];
}
