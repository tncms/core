<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

use RuntimeException;

/**
 * A storage write, flush, sync, close, create or rename failed during an upgrade
 * (CORE-UPGRADE-DISK-QUOTA-P0-R1). `$reason` is a stable code for diagnostics:
 * write_short | flush_failed | sync_failed | close_failed | create_failed |
 * rename_failed | verify_failed. PHP cannot reliably tell ENOSPC from EDQUOT or
 * inode exhaustion, so the code describes the failed operation, not its cause.
 */
final class UpgradeStorageException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
