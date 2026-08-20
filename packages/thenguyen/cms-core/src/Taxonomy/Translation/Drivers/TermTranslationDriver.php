<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Taxonomy\Translation\Drivers;

use TheNguyen\CMS\Taxonomy\Translation\Contracts\TaxonomyTranslationDriverInterface;
use TheNguyen\CMS\Taxonomy\Translation\TaxonomyTranslationFields;
use TheNguyen\CMS\Translation\Drivers\AbstractRelationalTranslationDriver;

/**
 * The first production Taxonomy Translation Platform driver (Phase 9.2C): the
 * bridge between the Translation Platform and the existing `cms_term_translations`
 * table (every taxonomy's per-locale name/slug/description/SEO), behind the `term`
 * namespace.
 *
 * It proves the Translation Platform can support a non-Content entity without any
 * platform modification: it reuses {@see AbstractRelationalTranslationDriver}
 * unchanged for all read/write/batch/diagnostics plumbing and only declares the
 * table, identity columns, namespace and the canonical taxonomy field set
 * ({@see TaxonomyTranslationFields::driverFields()}). The certified
 * `content_relational` driver is deliberately NOT reused — terms use a different
 * table and different field names (name≠title, single description, no
 * meta_keywords), which is exactly why {@see AbstractRelationalTranslationDriver}
 * is the designed extension seam.
 *
 * ONE DRIVER, EVERY TAXONOMY: because all taxonomies share `cms_term_translations`,
 * this single driver serves Category, Tag, Brand, Genre and every future/plugin
 * taxonomy with NO per-taxonomy code. Per the contract (§Core Rule) it NEVER
 * branches on a concrete taxonomy type — it operates purely over the term primary
 * key (`term_id`) and the shared field vocabulary.
 *
 * Per the Driver Standard it writes field storage ONLY: it never generates slugs,
 * sanitizes HTML, invalidates caches, detects locale, applies fallback, or fires
 * business events — those remain with `TaxonomyManager` and the resolver.
 *
 * Registered with the driver registry as `term_relational`, `asDefault: false` —
 * the Null driver stays the engine default and NO taxonomy is migrated to it in
 * this phase. It is engaged only when a future taxonomy adoption flag names it.
 */
final class TermTranslationDriver extends AbstractRelationalTranslationDriver implements TaxonomyTranslationDriverInterface
{
    public const DRIVER_NAME = 'term_relational';

    public const DRIVER_VERSION = '1.0.0';

    private const TABLE = 'cms_term_translations';

    private const FOREIGN_KEY = 'term_id';

    private const LOCALE_COLUMN = 'locale';

    private const NAMESPACE_ROOT = 'term';

    public function name(): string
    {
        return self::DRIVER_NAME;
    }

    public function version(): string
    {
        return self::DRIVER_VERSION;
    }

    protected function table(): string
    {
        return self::TABLE;
    }

    protected function foreignKey(): string
    {
        return self::FOREIGN_KEY;
    }

    protected function localeColumn(): string
    {
        return self::LOCALE_COLUMN;
    }

    protected function namespaceRoot(): string
    {
        return self::NAMESPACE_ROOT;
    }

    protected function translatableFields(): array
    {
        // Single source of truth — the canonical taxonomy field vocabulary
        // (name/slug/description/meta_title/meta_description). Shared with the
        // read/write adapters and admin so every taxonomy uses one field contract.
        return TaxonomyTranslationFields::driverFields();
    }
}
