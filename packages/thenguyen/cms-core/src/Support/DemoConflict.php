<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * EG-9 — the demo-import conflict classification model. Importer behaviour is
 * never a boolean "exists / does not exist"; every reconcilable outcome maps to
 * one of these classes so preview, provenance and (later) rollback share one
 * vocabulary.
 *
 * Ownership is decided by PROVENANCE (the demo's own imported ids + source
 * fingerprints), NEVER by a slug or title match. An unowned object that merely
 * collides on a slug/title is classified — never claimed.
 *
 * Phase 1 establishes and exercises the model; the exhaustive preview/rollback
 * engine that consumes every class lands in later phases.
 */
final class DemoConflict
{
    /** Provenance-owned object whose declarative source fingerprint is unchanged. */
    public const OWNED_MATCH = 'OWNED_MATCH';

    /** Provenance-owned object whose declarative source fingerprint changed. */
    public const OWNED_CHANGED = 'OWNED_CHANGED';

    /** A non-owned object already occupies the localized public slug. */
    public const UNOWNED_SAME_SLUG = 'UNOWNED_SAME_SLUG';

    /** A non-owned object shares a NON-DEFAULT locale (translated) slug. */
    public const UNOWNED_SAME_TRANSLATED_SLUG = 'UNOWNED_SAME_TRANSLATED_SLUG';

    /**
     * DOCUMENTARY ONLY (not in the certified runtime set). Core enforces no
     * title/name uniqueness constraint — only per-locale slug uniqueness via
     * cms_slugs — so a shared title is never a persistence conflict and is never
     * emitted. Retained as a named vocabulary item, not a runtime classification.
     */
    public const UNOWNED_SAME_TITLE = 'UNOWNED_SAME_TITLE';

    /**
     * DOCUMENTARY ONLY (not in the certified runtime set). A missing symbolic
     * reference is always reported by the domain-specific MEDIA_UNRESOLVED /
     * TAXONOMY_UNRESOLVED classes, so this generic form is never emitted through
     * the supported contract. Retained as vocabulary, not a runtime class.
     */
    public const SYMBOL_MISSING = 'SYMBOL_MISSING';

    /** The same symbolic key was declared more than once in one import run. */
    public const SYMBOL_DUPLICATE = 'SYMBOL_DUPLICATE';

    /** An author strategy could not resolve to a real user. */
    public const AUTHOR_UNRESOLVED = 'AUTHOR_UNRESOLVED';

    /** A `media:{key}` reference could not be resolved to imported/reused media. */
    public const MEDIA_UNRESOLVED = 'MEDIA_UNRESOLVED';

    /** A `category:{key}` / `tag:{key}` reference could not be resolved. */
    public const TAXONOMY_UNRESOLVED = 'TAXONOMY_UNRESOLVED';

    /** Every defined class (stable order), for documentation/tests. */
    public const ALL = [
        self::OWNED_MATCH,
        self::OWNED_CHANGED,
        self::UNOWNED_SAME_SLUG,
        self::UNOWNED_SAME_TRANSLATED_SLUG,
        self::UNOWNED_SAME_TITLE,
        self::SYMBOL_MISSING,
        self::SYMBOL_DUPLICATE,
        self::AUTHOR_UNRESOLVED,
        self::MEDIA_UNRESOLVED,
        self::TAXONOMY_UNRESOLVED,
    ];

    /**
     * The classes the importers actually EMIT through the supported contract —
     * the set backed by executable evidence. Excludes the documentary-only
     * UNOWNED_SAME_TITLE and SYMBOL_MISSING (see their constant docs).
     */
    public const RUNTIME = [
        self::OWNED_MATCH,
        self::OWNED_CHANGED,
        self::UNOWNED_SAME_SLUG,
        self::UNOWNED_SAME_TRANSLATED_SLUG,
        self::SYMBOL_DUPLICATE,
        self::AUTHOR_UNRESOLVED,
        self::MEDIA_UNRESOLVED,
        self::TAXONOMY_UNRESOLVED,
    ];
}
