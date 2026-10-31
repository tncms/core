<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * The bounded outcome codes of a {@see ContentActionDecision}
 * (CORE-CONTENT-ACTION-AUTHORIZATION-R1). Exactly one is `Allowed`.
 */
enum ContentActionReason: string
{
    case Allowed = 'allowed';
    case Unauthenticated = 'unauthenticated';
    case PermissionDenied = 'permission_denied';
    case OwnershipDenied = 'ownership_denied';
    case StatusDenied = 'status_denied';
    case LocaleDenied = 'locale_denied';
    case UnsupportedAction = 'unsupported_action';
    case UnsupportedSubject = 'unsupported_subject';
}
