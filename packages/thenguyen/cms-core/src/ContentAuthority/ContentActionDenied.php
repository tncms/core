<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown by {@see ContentActionAuthority::authorize()} for a refused action
 * (CORE-CONTENT-ACTION-AUTHORIZATION-R1). As an AuthorizationException it renders
 * as HTTP 403 with Laravel's generic message; the reason code is available to
 * the caller through {@see decision()} and is never put in the message.
 */
final class ContentActionDenied extends AuthorizationException
{
    public function __construct(private readonly ContentActionDecision $decision)
    {
        parent::__construct('This action is unauthorized.');
        $this->withStatus(403);
    }

    public function decision(): ContentActionDecision
    {
        return $this->decision;
    }
}
