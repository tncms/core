<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void register(string $type, callable $resolver, ?callable $renderer = null, array $options = [])
 * @method static bool hasType(string $type)
 * @method static array types()
 * @method static void forgetType(string $type)
 * @method static \TheNguyen\CMS\Support\Preview\PreviewDefinition|null definition(string $type)
 * @method static array definitions()
 * @method static int registeredCount()
 * @method static \TheNguyen\CMS\Contracts\Previewable|null resolve(string $type, string|int $key)
 * @method static callable|null renderer(string $type)
 * @method static string temporaryUrl(\TheNguyen\CMS\Contracts\Previewable $previewable, ?\DateTimeInterface $expiresAt = null, array $options = [])
 * @method static array metadata(\TheNguyen\CMS\Contracts\Previewable $previewable, array $options = [])
 * @method static \TheNguyen\CMS\Support\Preview\PreviewContext context(\TheNguyen\CMS\Contracts\Previewable $previewable, ?\Illuminate\Http\Request $request = null, array $extra = [])
 * @method static bool enabled()
 * @method static int ttlMinutes()
 * @method static \Carbon\CarbonImmutable defaultExpiry()
 * @method static \Symfony\Component\HttpFoundation\Response applyPreviewHeaders(\Symfony\Component\HttpFoundation\Response $response)
 *
 * @see \TheNguyen\CMS\Services\PreviewManager
 */
class Preview extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'cms.preview';
    }
}
