<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Drivers;

/**
 * The first production relational translation driver (Phase 9.0B): the bridge
 * between the Translation Platform and the existing `cms_content_translations`
 * table (Posts/Pages content fields), behind the `content` namespace.
 *
 * It adds no behaviour of its own — it only declares the table, the identity
 * columns, and the translatable columns; {@see AbstractRelationalTranslationDriver}
 * supplies all read/write/batch/diagnostics plumbing. Per the Driver Standard it
 * writes field storage ONLY: it never generates slugs, sanitizes HTML,
 * invalidates caches, detects locale, applies fallback, or fires business events.
 *
 * Registered with the driver registry as `content_relational`, `asDefault: false`
 * — the Null driver stays the engine default and NO business module is migrated
 * to it in this phase. It is engaged only when a future module flag names it.
 */
final class ContentTranslationDriver extends AbstractRelationalTranslationDriver
{
    public const DRIVER_NAME = 'content_relational';

    public const DRIVER_VERSION = '1.0.0';

    private const TABLE = 'cms_content_translations';

    private const FOREIGN_KEY = 'content_id';

    private const LOCALE_COLUMN = 'locale';

    private const NAMESPACE_ROOT = 'content';

    /** The user-authored, per-locale content columns (mirrors the migration). */
    private const FIELDS = [
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_title',
        'meta_description',
        'meta_keywords',
    ];

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
        return self::FIELDS;
    }
}
