<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Exceptions;

use RuntimeException;
use TheNguyen\CMS\Support\UserLifecycle\UserDeletionVeto;

/**
 * The throw-style equivalent of returning a {@see UserDeletionVeto} from a
 * user-deletion pre-delete handler (CORE-USER-LIFECYCLE-1, v1.0.0-beta.7.1.31).
 *
 * A handler may EITHER return a veto OR throw this exception; the
 * {@see \TheNguyen\CMS\Services\UserDeletionManager} treats both identically — a
 * deterministic, EXPECTED veto that rolls back and is reported to the caller as a
 * result (never as an error / 500). Any OTHER throwable from a handler is
 * UNEXPECTED: the manager does not convert it into a veto, rolls back, and
 * re-throws so the deletion fails closed.
 */
final class UserDeletionVetoException extends RuntimeException
{
    public function __construct(public readonly UserDeletionVeto $veto)
    {
        parent::__construct($veto->message());
    }

    public function veto(): UserDeletionVeto
    {
        return $this->veto;
    }
}
