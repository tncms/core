<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Cache;

use TheNguyen\CMS\Translation\Contracts\PrunableTranslationCacheInterface;
use TheNguyen\CMS\Translation\Contracts\TranslationCacheInterface;
use TheNguyen\CMS\Translation\DTOs\TranslationResult;

/**
 * Request-scoped, in-memory translation cache. Registered as a singleton, so it
 * lives for one request and resets on the next — no cross-request leakage. A
 * future phase can add a Redis-backed implementation behind the same contract.
 *
 * Also implements {@see PrunableTranslationCacheInterface} (Phase 8.2) so the
 * entity layer can invalidate a single field instead of flushing everything.
 */
final class RuntimeTranslationCache implements TranslationCacheInterface, PrunableTranslationCacheInterface
{
    /** @var array<string, TranslationResult> */
    private array $store = [];

    public function get(string $key): ?TranslationResult
    {
        return $this->store[$key] ?? null;
    }

    public function put(string $key, TranslationResult $result): void
    {
        $this->store[$key] = $result;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function forget(string $key): void
    {
        unset($this->store[$key]);
    }

    public function flush(): void
    {
        $this->store = [];
    }

    public function forgetContaining(string $fragment): int
    {
        if ($fragment === '') {
            return 0;
        }

        $removed = 0;

        foreach (array_keys($this->store) as $key) {
            if (str_contains($key, $fragment)) {
                unset($this->store[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}
