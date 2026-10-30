<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use TheNguyen\CMS\Content\Extension\ContentFallbackPolicy;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;

/**
 * Trusted, server-derived context of a content write
 * (CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * Nothing in here can be chosen by a request: there is no public constructor,
 * the extension slug is derived from the calling plugin's file on disk, and a
 * system context can only be minted by Core/host code for an allowlisted
 * reason. A request field such as `source`, `channel` or a header is never read.
 *
 *  - interactive: a Core admin adapter acting for the authenticated user. RBAC
 *    applies and an expected revision is mandatory.
 *  - extension:   code of an ACTIVE plugin acting for an actor. RBAC applies to
 *    the actor, an expected revision is mandatory, and the owner guard sees the
 *    derived slug (so an owner can recognise its own sanctioned writes).
 *  - system:      a bounded Core operation (installer seed, demo import). Skips
 *    RBAC and the revision requirement, never the owner guards.
 *  - legacy:      the pre-R1 signature without a context. Requires an
 *    authenticated actor (RBAC applies); refused for managed content. The
 *    revision may be omitted only by host/Core code on ordinary content; a
 *    legacy call made from PLUGIN code must carry the expected revision.
 */
final class ContentWriteContext
{
    public const INTERACTIVE = 'interactive';

    public const EXTENSION = 'extension';

    public const SYSTEM = 'system';

    public const LEGACY = 'legacy';

    /** Bounded reasons a system context may be minted for. */
    public const SYSTEM_REASONS = ['install_seed', 'demo_import'];

    private function __construct(
        public readonly string $origin,
        public readonly ?Authenticatable $actor,
        public readonly ?string $extension = null,
        public readonly ?string $systemReason = null,
        public readonly bool $claimsOwnership = false,
        public readonly ?string $legacyCaller = null,
        public readonly ?ContentFallbackPolicy $ownedFallback = null,
        public readonly bool $actorIsSessionUser = false,
        public readonly bool $actorMismatch = false,
        public readonly ?Authenticatable $operator = null,
    ) {}

    /** A Core admin adapter acting for the given (default: authenticated) user. */
    public static function interactive(?Authenticatable $actor = null): self
    {
        return new self(self::INTERACTIVE, $actor ?? auth()->user());
    }

    /**
     * The calling ACTIVE plugin acting for an actor. The slug is derived from
     * the nearest plugin frame on the call stack; non-plugin callers get a
     * LogicException.
     */
    public static function extension(?Authenticatable $actor = null): self
    {
        $slug = self::callingPlugin();

        if ($slug === null || $slug === '') {
            throw new LogicException('An extension content-write context can only be created by the code of an active plugin.');
        }

        // CORE-UNIFIED-OWNERSHIP-R1: a plugin cannot act as someone other
        // than the signed-in user. While an HTTP request is served the actor
        // must BE the signed-in user; outside one (CLI/queue) an explicit
        // actor is still accepted for ordinary writes, but ownership changes
        // always require the signed-in user (see ContentWriteGate).
        $session = auth()->user();
        $actor ??= $session;
        $isSession = $actor !== null && $session !== null && self::sameUser($actor, $session);
        $mismatch = $actor !== null && ! $isSession && ($session !== null || self::inHttpRequest());

        return new self(self::EXTENSION, $actor, $slug, actorIsSessionUser: $isSession, actorMismatch: $mismatch);
    }

    /**
     * An operator-level ownership change made from Core/host code only (the
     * `cms:content-owner` command): the operator is a user who must hold the
     * strict recovery permission. Never available to plugin code.
     */
    public static function operator(Authenticatable $operator): self
    {
        if (self::callingPlugin() !== null) {
            throw new LogicException('An operator ownership context cannot be created by plugin code.');
        }

        return new self(self::INTERACTIVE, $operator, operator: $operator);
    }

    /** Whether an HTTP request is being served (set by the MarkHttpRequest middleware). */
    public static function inHttpRequest(): bool
    {
        if (! app()->runningInConsole()) {
            return true;
        }

        return app()->bound('cms.http_request_active') && app('cms.http_request_active') === true;
    }

    private static function sameUser(Authenticatable $a, Authenticatable $b): bool
    {
        return $a::class === $b::class && (string) $a->getAuthIdentifier() === (string) $b->getAuthIdentifier();
    }

    /**
     * A bounded Core system operation. Refused when called from plugin code or
     * for a reason outside {@see SYSTEM_REASONS}.
     */
    public static function system(string $reason, ?Authenticatable $actor = null): self
    {
        if (! in_array($reason, self::systemReasons(), true)) {
            throw new LogicException("Unknown system content-write reason [{$reason}].");
        }

        if (self::callingPlugin() !== null) {
            throw new LogicException('A system content-write context cannot be created by plugin code.');
        }

        return new self(self::SYSTEM, $actor, systemReason: $reason);
    }

    /**
     * Allowed system reasons: Core's own plus host-configured ones
     * (`cms.content_authority.system_reasons`, e.g. a host import job). Host
     * configuration only — plugin code can never mint a system context anyway.
     *
     * @return list<string>
     */
    public static function systemReasons(): array
    {
        $extra = array_filter(
            (array) config('cms.content_authority.system_reasons', []),
            static fn ($r): bool => is_string($r) && preg_match('/^[a-z0-9_]{1,40}$/', $r) === 1,
        );

        return array_values(array_unique([...self::SYSTEM_REASONS, ...$extra]));
    }

    /** The implicit context of a call without one (pre-R1 signature). */
    public static function legacy(): self
    {
        return new self(self::LEGACY, auth()->user(), legacyCaller: self::callingPlugin());
    }

    /**
     * The same extension context, additionally claiming ownership of content it
     * creates (sets `managed_by` to the derived slug on create).
     */
    public function claimingOwnership(ContentFallbackPolicy $fallback = ContentFallbackPolicy::Hide404): self
    {
        if ($this->origin !== self::EXTENSION) {
            throw new LogicException('Only an extension context can claim content ownership.');
        }

        return new self($this->origin, $this->actor, $this->extension, null, true, null, $fallback, $this->actorIsSessionUser, $this->actorMismatch);
    }

    /** The trusted origin recorded in the ownership audit. */
    public function auditOrigin(): string
    {
        return $this->operator !== null ? 'operator' : $this->origin;
    }

    public function isSystem(): bool
    {
        return $this->origin === self::SYSTEM;
    }

    /** Whether an expected revision is mandatory for writes in this context. */
    public function requiresRevision(): bool
    {
        return $this->origin === self::INTERACTIVE || $this->origin === self::EXTENSION
            || ($this->origin === self::LEGACY && $this->legacyCaller !== null);
    }

    /** Owning plugin of the nearest plugin frame, '' for an unowned plugin file, null when none. */
    private static function callingPlugin(): ?string
    {
        /** @var ExtensionIdentityResolver $identity */
        $identity = app(ExtensionIdentityResolver::class);

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && $identity->isUnderPluginsRoot($file)) {
                return $identity->ownerOfFile($file) ?? '';
            }
        }

        return null;
    }
}
