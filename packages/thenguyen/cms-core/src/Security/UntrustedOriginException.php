<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Security;

use RuntimeException;

/**
 * APP_URL is not a usable trusted origin for security-sensitive links
 * (CORE-AUTH-TRUSTED-ORIGIN-SECURITY-R1). The message names the reason only,
 * never the configured value (it could hold credentials).
 */
final class UntrustedOriginException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Security links are disabled: '.$reason.'. Set APP_URL to the public origin of the site (for example https://www.example.com).');
    }
}
