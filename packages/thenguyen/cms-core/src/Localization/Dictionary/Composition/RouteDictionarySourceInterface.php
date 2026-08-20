<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Composition;

/**
 * P6.2 — a single Route Dictionary source (build-time only).
 *
 * A source contributes raw dictionary data (`key => [locale => segment]`) to be MERGED by the
 * {@see RouteDictionaryComposer} before the immutable Runtime dictionary is built. A source owns
 * no Runtime logic, no projection, and no validation — it is pure data + a merge position.
 *
 * Precedence: sources merge in ascending {@see priority()} (higher priority wins on override);
 * {@see id()} is a stable tiebreaker and appears in conflict/override diagnostics.
 */
interface RouteDictionarySourceInterface
{
    /** A stable, unique source identity (e.g. "core.persistence", "theme", "plugin.blog"). */
    public function id(): string;

    /** Merge priority — higher wins when two sources set the same (key, locale). */
    public function priority(): int;

    /**
     * The raw dictionary payload contributed by this source.
     *
     * @return array<string, array<string, string>>
     */
    public function payload(): array;
}
