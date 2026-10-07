<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\UserLifecycle;

/**
 * A typed, deterministic veto of a user deletion (CORE-USER-LIFECYCLE-1,
 * v1.0.0-beta.7.1.31).
 *
 * Returned (or thrown via {@see UserDeletionVetoException}) by a pre-delete
 * handler to ABORT a supported user deletion before any destructive mutation.
 * Immutable by construction.
 *
 *  - $code is an opaque, stable identifier the vetoing extension owns (e.g.
 *    "business_reviews.last_active_owner"). Core never interprets it; it exists
 *    so logs/UX can branch on a machine value instead of a human string.
 *  - $message is a SAFE, presentable, already-localized sentence the admin UI
 *    renders verbatim as a danger notification. The extension asserts it is safe
 *    (no secrets, no untrusted HTML). Core escapes it as plain text and never
 *    renders arbitrary exception strings in its place.
 *  - $metadata is optional NON-SENSITIVE context (ids, counts) for logging or a
 *    richer message the extension itself builds. Core does not render it.
 *
 * A veto is an EXPECTED business outcome — it is never an error. An unexpected
 * handler exception is handled separately (fail-closed) and is never represented
 * as a veto.
 */
final class UserDeletionVeto
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $metadata = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public static function make(string $code, string $message, array $metadata = []): self
    {
        return new self($code, $message, $metadata);
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
