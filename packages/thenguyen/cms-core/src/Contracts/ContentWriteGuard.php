<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Contracts;

use TheNguyen\CMS\ContentAuthority\ContentWriteDecision;
use TheNguyen\CMS\ContentAuthority\ContentWriteIntent;

/**
 * Vetoable write authority for extension-managed content
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * An active plugin registers ONE guard with `cms.content.guards`; its identity
 * is derived server-side from the class file and the registering frame. Core
 * calls the guard for every sanctioned write to content whose `managed_by`
 * marker names that plugin, INSIDE the write transaction, after the row is
 * locked and before anything is written.
 *
 * Return {@see ContentWriteDecision::allow()} or ::deny(). Throwing, returning
 * anything else or taking too long to decide is treated as a refusal (fail
 * closed). The guard must not write to the database or perform side effects.
 */
interface ContentWriteGuard
{
    public function check(ContentWriteIntent $intent): ContentWriteDecision;
}
