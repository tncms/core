<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

use RuntimeException;

/**
 * Component output failed the interactive profile (D-6: the whole component is
 * rejected). The reason is a fixed code such as `element` or `url`; it never
 * echoes the offending markup, so it is safe to log.
 */
final class InteractiveProfileViolation extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Interactive profile violation: '.$reason);
    }
}
