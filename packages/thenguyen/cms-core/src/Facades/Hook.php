<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1)
 * @method static void doAction(string $hook, mixed ...$args)
 * @method static string captureAction(string $hook, mixed ...$args)
 * @method static bool hasAction(string $hook)
 * @method static void removeAction(string $hook, callable|string|null $callback = null)
 * @method static array actions()
 * @method static void addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1)
 * @method static mixed applyFilters(string $hook, mixed $value, mixed ...$args)
 * @method static bool hasFilter(string $hook)
 * @method static void removeFilter(string $hook, callable|string|null $callback = null)
 * @method static array filters()
 *
 * @see \TheNguyen\CMS\Services\HookManager
 */
class Hook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.hooks';
    }
}
