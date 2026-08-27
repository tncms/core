<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

use RuntimeException;

/**
 * Remote-update domain failure (CORE-UPGRADE-2).
 *
 * Carries a stable machine `reason` alongside the human message so callers and
 * tests can branch on the failure class without string-matching the message.
 * Never carries a token, secret or Authorization header (§security).
 */
final class UpdateException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
