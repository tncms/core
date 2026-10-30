<?php

declare(strict_types=1);

namespace TheNguyen\CMS\ContentAuthority;

use TheNguyen\CMS\Contracts\ContentWriteGuard;
use TheNguyen\CMS\Services\ExtensionIdentityResolver;
use Throwable;

/**
 * Registry of extension content-write guards (`cms.content.guards`,
 * CORE-STRUCTURED-CONTENT-AUTHORITY-R1).
 *
 * Registration authority mirrors the trusted-component registry: the guard class
 * must live in an ACTIVE plugin and the registering call must come from that same
 * plugin's code; one guard per plugin; closed once the first route is matched.
 * Identity is never taken from anything the caller declares.
 *
 * A guard is only looked up for content whose `managed_by` names its owner, and
 * only while that owner is still active (re-checked at lookup time).
 */
final class ContentWriteGuardRegistry
{
    /** @var array<string, ContentWriteGuard> owner slug => guard */
    private array $guards = [];

    /** @var list<array{owner: string, reason: string}> */
    private array $diagnostics = [];

    private bool $frozen = false;

    public function __construct(private readonly ExtensionIdentityResolver $identity) {}

    public function register(ContentWriteGuard $guard): bool
    {
        try {
            if ($this->frozen) {
                return $this->deny('-', 'registry frozen');
            }

            $owner = $this->identity->ownerOf($guard);

            if ($owner === null) {
                return $this->deny('-', 'guard not owned by an active plugin');
            }

            $callSite = $this->callSitePlugin();

            if ($callSite === null) {
                return $this->deny($owner, 'registration must come from the owner plugin');
            }

            if ($callSite !== $owner) {
                return $this->deny($owner, 'owner mismatch');
            }

            if (isset($this->guards[$owner])) {
                return $this->deny($owner, 'duplicate guard');
            }

            $this->guards[$owner] = $guard;

            return true;
        } catch (Throwable) {
            return $this->deny('-', 'registration failed');
        }
    }

    /** The guard of an owner that is still active, or null (owner missing/inactive). */
    public function guardFor(string $owner): ?ContentWriteGuard
    {
        $guard = $this->guards[$owner] ?? null;

        if ($guard === null || $this->identity->ownerOf($guard) !== $owner) {
            return null;
        }

        return $guard;
    }

    /** @return list<string> */
    public function owners(): array
    {
        return array_keys($this->guards);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    /** @return list<array{owner: string, reason: string}> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    private function deny(string $owner, string $reason): bool
    {
        $this->diagnostics[] = ['owner' => $owner, 'reason' => $reason];

        return false;
    }

    /** Owning plugin of the nearest plugin frame ('' for an unowned plugin file), null when none. */
    private function callSitePlugin(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;

            if (is_string($file) && $this->identity->isUnderPluginsRoot($file)) {
                return $this->identity->ownerOfFile($file) ?? '';
            }
        }

        return null;
    }
}
