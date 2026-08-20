<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Administration;

/**
 * P6.4 — the ownership of a single effective `(RouteKey, locale)` segment, for administration.
 *
 * Ownership decides editability: only {@see self::Project} entries are administrator-editable.
 * {@see self::Core} (the frozen seed default) and {@see self::Plugin} (a registered plugin source)
 * are inherited and READ-ONLY — the admin overrides a Core localized segment by creating a Project
 * entry, never by editing the Core seed constant or a plugin source directly.
 */
enum EntryOwnership: string
{
    /** The frozen Platform seed default (PlatformRouteDictionary), still un-overridden. Read-only. */
    case Core = 'core';

    /** Contributed by a registered plugin Dictionary source (P6.3). Read-only. */
    case Plugin = 'plugin';

    /** A project-owned override/addition persisted in the store. Editable. */
    case Project = 'project';

    public function editable(): bool
    {
        return $this === self::Project;
    }

    public function label(): string
    {
        return match ($this) {
            self::Core => 'Core',
            self::Plugin => 'Plugin',
            self::Project => 'Project',
        };
    }
}
