<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, \TheNguyen\CMS\Support\DemoPackage> discover()
 * @method static \TheNguyen\CMS\Support\DemoPackage|null find(string $owner, string $slug)
 * @method static \TheNguyen\CMS\Support\ImportResult import(\TheNguyen\CMS\Support\DemoPackage $package)
 * @method static \TheNguyen\CMS\Support\ImportResult reset(\TheNguyen\CMS\Support\DemoPackage $package)
 *
 * @see \TheNguyen\CMS\Services\DemoImporter
 */
class DemoImporter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.demo_importer';
    }
}
