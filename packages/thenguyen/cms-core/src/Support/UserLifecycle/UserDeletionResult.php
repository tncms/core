<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\UserLifecycle;

/**
 * Immutable outcome of a supported user deletion routed through the
 * {@see \TheNguyen\CMS\Services\UserDeletionManager} (CORE-USER-LIFECYCLE-1,
 * v1.0.0-beta.7.1.31).
 *
 * Exactly one of three shapes:
 *  - deleted:   the account was removed (FK cascades ran) — {@see wasDeleted()}.
 *  - vetoed:    a handler aborted the deletion before any mutation; carries the
 *               typed {@see UserDeletionVeto} — {@see wasVetoed()}.
 *  - absent:    the target no longer existed when the delete ran (idempotent
 *               no-op) — neither deleted nor vetoed.
 *
 * A veto is an expected business result returned normally, never an exception;
 * unexpected handler failures do NOT produce a result — they roll back and
 * propagate (fail-closed).
 */
final class UserDeletionResult
{
    private function __construct(
        private readonly bool $deleted,
        private readonly ?UserDeletionVeto $veto,
    ) {}

    public static function deleted(bool $deleted = true): self
    {
        return new self($deleted, null);
    }

    public static function vetoed(UserDeletionVeto $veto): self
    {
        return new self(false, $veto);
    }

    /** The target row was gone before deletion ran (idempotent no-op). */
    public static function absent(): self
    {
        return new self(false, null);
    }

    public function wasDeleted(): bool
    {
        return $this->deleted;
    }

    public function wasVetoed(): bool
    {
        return $this->veto !== null;
    }

    public function veto(): ?UserDeletionVeto
    {
        return $this->veto;
    }
}
