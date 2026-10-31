<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

/**
 * Immutable answer of {@see ContentActionAuthority}
 * (CORE-CONTENT-ACTION-AUTHORIZATION-R1). Carries only the action and a bounded
 * reason code — never content, permission or user data.
 *
 * CORE-CONTENT-AUTHORIZATION-R2: an allowed decision also states its scope —
 * `privateDraft` (allowed only as a private draft, never as a public write) and
 * `owner` (the verified owning extension when it was asked as owner of managed
 * content). A refusal always carries `false` / `null`.
 */
final class ContentActionDecision
{
    private function __construct(
        public readonly ?ContentAction $action,
        public readonly ContentActionReason $reason,
        public readonly bool $privateDraft = false,
        public readonly ?string $owner = null,
    ) {}

    public static function allow(ContentAction $action, bool $privateDraft = false, ?string $owner = null): self
    {
        return new self($action, ContentActionReason::Allowed, $privateDraft, $owner);
    }

    /** A refusal; `Allowed` is never a refusal reason, so it maps to permission_denied. */
    public static function deny(?ContentAction $action, ContentActionReason $reason): self
    {
        return new self($action, $reason === ContentActionReason::Allowed ? ContentActionReason::PermissionDenied : $reason);
    }

    public function allowed(): bool
    {
        return $this->reason === ContentActionReason::Allowed;
    }

    public function denied(): bool
    {
        return ! $this->allowed();
    }
}
