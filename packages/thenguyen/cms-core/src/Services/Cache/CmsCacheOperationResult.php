<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services\Cache;

/**
 * Immutable outcome of a CMS cache operation (CORE-OPTIMIZE-2 §15).
 *
 * A small, honest record an operator surface can render without reconstructing
 * state: which operation ran against which stable domain ID, whether it actually
 * succeeded (never a blind "no exception == success"), the epoch before/after an
 * invalidation, how much a warm processed/failed, how long it took, and a
 * translation key for the user-facing message. Deliberately NOT a job/queue
 * abstraction — just a value.
 */
final class CmsCacheOperationResult
{
    /**
     * @param  'clear'|'rebuild'  $operation
     * @param  array<string,mixed>  $context  extra machine-readable, secret-free detail (e.g. warm scope)
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $domain,
        public readonly bool $success,
        public readonly string $messageKey,
        public readonly ?int $before = null,
        public readonly ?int $after = null,
        public readonly int $processedCount = 0,
        public readonly int $failedCount = 0,
        public readonly int $durationMs = 0,
        public readonly array $context = [],
    ) {}

    public static function clear(bool $success, int $before, int $after, string $messageKey): self
    {
        return new self(
            operation: 'clear',
            domain: \TheNguyen\CMS\Services\PublicContentCacheManager::DOMAIN_ID,
            success: $success,
            messageKey: $messageKey,
            before: $before,
            after: $after,
        );
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function rebuild(
        bool $success,
        string $messageKey,
        int $processedCount = 0,
        int $failedCount = 0,
        int $durationMs = 0,
        array $context = [],
    ): self {
        return new self(
            operation: 'rebuild',
            domain: \TheNguyen\CMS\Services\PublicContentCacheManager::DOMAIN_ID,
            success: $success,
            messageKey: $messageKey,
            processedCount: $processedCount,
            failedCount: $failedCount,
            durationMs: $durationMs,
            context: $context,
        );
    }

    /**
     * Stable, secret-free structured form for logs / UI / tests.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'domain' => $this->domain,
            'success' => $this->success,
            'message_key' => $this->messageKey,
            'before' => $this->before,
            'after' => $this->after,
            'processed_count' => $this->processedCount,
            'failed_count' => $this->failedCount,
            'duration_ms' => $this->durationMs,
            'context' => $this->context,
        ];
    }
}
