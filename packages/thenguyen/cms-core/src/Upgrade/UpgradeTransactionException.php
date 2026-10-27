<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;

/**
 * A Core upgrade transaction step failed (CORE-UPGRADE-WIN-R1).
 *
 * The message is operator-safe: it names install-relative paths only (never an
 * absolute path, secret or environment value). `$outcome` is the VERIFIED state
 * the live tree was left in; `$diagnostics` carries the structured detail that
 * is written to protected storage only.
 */
final class UpgradeTransactionException extends RuntimeException
{
    /**
     * @param  array<string,mixed>  $diagnostics
     */
    public function __construct(
        string $message,
        public readonly string $outcome,
        public readonly string $phase,
        public readonly array $diagnostics = [],
    ) {
        parent::__construct($message);
    }
}
