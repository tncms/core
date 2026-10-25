<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support\TrustedComponents;

/**
 * Server-derived facts about one component render (CORE-EXTENSION-TRUST S2).
 *
 * Built by Core, never from request input. It deliberately carries scalars
 * only: no user model, profile, session or request object, so the generic
 * render context can never become a channel for private data. A provider that
 * needs more must authorize and fetch it itself, under its own policy.
 */
final readonly class TrustedRenderContext
{
    public function __construct(
        public string $componentId,
        public string $owner,
        /** The requesting plugin slug, or `@host`. */
        public string $invoker,
        /** public | preview | editor */
        public string $mode,
        public string $locale,
        public ?string $routeName,
        public bool $isAuthenticated,
        public ?int $userId,
    ) {}

    /** Preview and editor renders must be side-effect free and non-submittable. */
    public function isReadOnly(): bool
    {
        return $this->mode !== 'public';
    }

    public function isPreview(): bool
    {
        return $this->mode === 'preview';
    }

    /** Strict (never fail-open) permission check for the current viewer. */
    public function can(string $permission): bool
    {
        try {
            return app('cms.permission')->userCanStrict(auth()->user(), $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}
