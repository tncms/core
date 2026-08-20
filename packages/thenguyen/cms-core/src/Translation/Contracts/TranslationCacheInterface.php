<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\TranslationResult;

/**
 * Cache seam for resolved translations. The bundled implementation is
 * request-scoped ({@see \TheNguyen\CMS\Translation\Cache\RuntimeTranslationCache});
 * a future phase may back this with Redis without touching callers.
 *
 * @since 1.0
 *
 * @stable
 */
interface TranslationCacheInterface
{
    public function get(string $key): ?TranslationResult;

    public function put(string $key, TranslationResult $result): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function flush(): void;
}
