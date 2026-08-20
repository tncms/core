<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\DTOs;

/**
 * The write-side sibling of {@see TranslationContext} (Driver Standard §2.1/§5).
 *
 * Carries the intent a driver needs to write safely: whether the caller already
 * opened a transaction the driver should JOIN (rather than nest a second commit),
 * the acting user, and an optional idempotency key for ledger-style stores. All
 * optional — a bare context is a valid standalone write. Immutable.
 */
final class WriteContext
{
    public function __construct(
        public readonly bool $withinTransaction = false,
        public readonly ?int $actorId = null,
        public readonly ?string $idempotencyKey = null,
    ) {}

    public static function default(): self
    {
        return new self;
    }

    /**
     * Signals the driver to join the caller's already-open transaction instead of
     * opening its own.
     */
    public static function joining(): self
    {
        return new self(withinTransaction: true);
    }

    public function inTransaction(): bool
    {
        return $this->withinTransaction;
    }
}
