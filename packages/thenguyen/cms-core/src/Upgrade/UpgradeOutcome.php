<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Verified end states of a Core upgrade transaction (CORE-UPGRADE-WIN-R1).
 *
 * An outcome is only ever reported after it was VERIFIED against the plan and
 * the observed filesystem/database — never because copy/rename calls returned
 * true. `recovered=true` on the persisted state maps to ROLLED_BACK_VERIFIED
 * and nothing else.
 */
final class UpgradeOutcome
{
    /** The plan was refused before any live mutation (nothing changed). */
    public const REJECTED = 'rejected';

    /** Every promoted entry, the ownership record, version and health were verified. */
    public const COMMITTED_VERIFIED = 'committed_verified';

    /** Live Core files, ownership record and (when touched) database match the pre-upgrade baseline. */
    public const ROLLED_BACK_VERIFIED = 'rolled_back_verified';

    /** Automatic recovery could not be verified; retry is blocked until recovery succeeds. */
    public const RECOVERY_REQUIRED = 'recovery_required';

    public const ALL = [self::REJECTED, self::COMMITTED_VERIFIED, self::ROLLED_BACK_VERIFIED, self::RECOVERY_REQUIRED];
}
