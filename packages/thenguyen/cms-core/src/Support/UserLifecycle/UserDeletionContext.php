<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\UserLifecycle;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Immutable context handed to every user-deletion pre-delete handler
 * (CORE-USER-LIFECYCLE-1, v1.0.0-beta.7.1.31).
 *
 * Carries ONLY what an extension legitimately needs to decide whether to veto:
 * the stable id of the target account, the acting administrator (when a request
 * initiated the deletion), and an opaque source label describing which supported
 * path is running.
 *
 * Privacy contract: this object deliberately does NOT expose the target User
 * model, password hashes, tokens, sessions, MFA secrets, or any other sensitive
 * attribute. A handler that needs more about the target queries its OWN tables by
 * {@see userId()}. The actor is exposed as the framework Authenticatable (same
 * posture as {@see \TheNguyen\CMS\Support\Hooks\HookContext::user()}); prefer
 * {@see actorId()} when only the id is needed.
 *
 * Actor-null semantics: a null actor means the deletion was NOT initiated by an
 * authenticated web request (CLI, queued job, or programmatic call). Handlers
 * MUST treat a null actor as a non-interactive/system initiator and never assume
 * a current user.
 */
final class UserDeletionContext
{
    /** The deletion runs from the Filament admin UserResource / EditUser page. */
    public const SOURCE_ADMIN = 'admin.user_resource';

    /** The initiating path is unknown or unspecified. */
    public const SOURCE_UNKNOWN = 'unknown';

    public function __construct(
        private readonly int $userId,
        private readonly ?Authenticatable $actor = null,
        private readonly string $source = self::SOURCE_UNKNOWN,
    ) {}

    /** Stable primary key of the account about to be deleted. */
    public function userId(): int
    {
        return $this->userId;
    }

    /**
     * The acting administrator, or null for a non-interactive/system deletion.
     */
    public function actor(): ?Authenticatable
    {
        return $this->actor;
    }

    /** Stable id of the actor, or null when there is no authenticated actor. */
    public function actorId(): ?int
    {
        if ($this->actor === null) {
            return null;
        }

        $key = $this->actor->getAuthIdentifier();

        return is_numeric($key) ? (int) $key : null;
    }

    /** Opaque label for the supported deletion path (see SOURCE_* constants). */
    public function source(): string
    {
        return $this->source;
    }
}
