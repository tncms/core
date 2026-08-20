<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string sanitize(?string $html)
 *
 * @see \TheNguyen\CMS\Services\HtmlSanitizer
 */
class Html extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.html';
    }
}
