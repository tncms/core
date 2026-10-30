<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * A guard's answer to a {@see ContentWriteIntent}. Anything other than an
 * instance of this class (or a malformed denial) is treated as a guard failure
 * and the write is refused.
 */
final class ContentWriteDecision
{
    private function __construct(
        public readonly bool $allowed,
        public readonly string $code,
        public readonly string $message,
    ) {}

    public static function allow(): self
    {
        return new self(true, '', '');
    }

    /**
     * @param  string  $code  short machine code, [a-z0-9_.-]{1,64}
     * @param  string  $message  safe, user-facing sentence
     */
    public static function deny(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
