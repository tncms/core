<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Events;

/**
 * CORE-L10N A2 — the ONE canonical platform event for a public locale change.
 *
 * Emitted by the single {@see \TheNguyen\CMS\Localization\LocaleTransition}
 * runtime after a valid locale switch is persisted. Consumers (SEO, analytics,
 * search, preview, audit, notifications, future plugins) subscribe via the
 * platform event dispatcher; the runtime never knows its subscribers and carries
 * no plugin, storage, or presentation knowledge — only the locale facts.
 */
final class LocaleChanged
{
    public function __construct(
        public readonly string $previousLocale,
        public readonly string $newLocale,
    ) {}
}
