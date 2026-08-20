<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Revision\Contracts;

/**
 * An entity that opts into locale-aware revisions. A pure, additive interface —
 * implementing it adds no columns. The entity owns exactly what a snapshot
 * captures for one locale, so the revision layer stays above the storage/driver
 * layer and never touches translation columns directly.
 *
 * @since 1.0
 *
 * @stable
 */
interface RevisionableInterface
{
    /** Stable polymorphic type, e.g. 'content' | 'term' | 'menu_item' | 'product'. */
    public function revisionEntityType(): string;

    /** The owning record's stable primary key. */
    public function revisionEntityId(): int|string;

    /**
     * The locales this entity can be revised for — normally
     * LanguageManager::getPublicLocales(). Used for diagnostics/fan-out helpers;
     * recording itself is always driven by the single locale a write touched.
     *
     * @return array<int, string>
     */
    public function revisionableLocales(): array;

    /**
     * The localized field map for ONE locale — exactly the payload the write
     * persists (e.g. title/slug/excerpt/content/SEO for content, plus any custom
     * localized fields the entity chooses to expose). An unauthored locale
     * returns an empty map.
     *
     * @return array<string, mixed>
     */
    public function snapshotForLocale(string $locale): array;
}
