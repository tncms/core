<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Contracts;

use TheNguyen\CMS\Translation\DTOs\TranslationRecord;
use TheNguyen\CMS\Translation\DTOs\WriteContext;

/**
 * The record-oriented production surface for a relational translation driver
 * (Phase 9.0B), realising the TN CMS Translation Driver Standard v1 (§2.1) for a
 * per-locale relational store.
 *
 * It extends the minimal engine {@see TranslationDriverInterface} (name/get/has/
 * all) — so an implementation registers with the driver registry and the resolver
 * reaches it unchanged — and adds the efficient entity-level read/write surface a
 * compatibility adapter consumes.
 *
 * A driver owns ONLY storage access for its domain. It never decides a locale,
 * applies fallback, sanitizes HTML, reserves slugs, or flushes a cache — those
 * belong to the resolver and the compatibility adapter (Driver Standard §1.2/§5.3).
 *
 * @since 1.0
 *
 * @stable
 */
interface RelationalTranslationDriverInterface extends TranslationDriverInterface
{
    /** Semantic version of the driver implementation. */
    public function version(): string;

    /** Does this driver own the given namespace (e.g. 'content' / 'content.title')? */
    public function supports(string $namespace): bool;

    /** Whether the driver may write. Read-only drivers return false. */
    public function isWritable(): bool;

    /**
     * The operations this driver implements.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;

    // ── read (returns exactly what is stored; NEVER fallback, NEVER infer locale) ──

    /** One locale's stored record, or null when that `(entity, locale)` has none. */
    public function read(int|string $entityId, string $locale): ?TranslationRecord;

    /**
     * Every stored locale for the entity, keyed by locale.
     *
     * @return array<string, TranslationRecord>
     */
    public function readAllLocales(int|string $entityId): array;

    /**
     * Read many entities in ONE round-trip (no N+1). Every requested id is present
     * in the result (empty locale-map when it has no rows).
     *
     * @param  array<int, int|string>  $entityIds
     * @return array<int|string, array<string, TranslationRecord>>
     */
    public function batchRead(array $entityIds): array;

    /** Whether any row exists for the entity (optionally for one locale). */
    public function exists(int|string $entityId, ?string $locale = null): bool;

    // ── write (atomic; typed exceptions; NEVER slugs/sanitize/cache/events) ──

    /** Insert one record. Throws on a duplicate `(entity, locale)` / `(locale, slug)`. */
    public function create(TranslationRecord $record, ?WriteContext $context = null): TranslationRecord;

    /** Update an existing record's provided fields. Idempotent; a missing row is a no-op. */
    public function update(TranslationRecord $record, ?WriteContext $context = null): TranslationRecord;

    /**
     * Upsert many records as ONE atomic, idempotent unit of work (one statement).
     *
     * @param  array<int, TranslationRecord>  $records
     */
    public function batchWrite(array $records, ?WriteContext $context = null): void;

    /** Remove an entity's rows (one locale, or all). Idempotent; returns rows removed. */
    public function delete(int|string $entityId, ?string $locale = null, ?WriteContext $context = null): int;

    // ── lifecycle ──────────────────────────────────────────────────────────────

    /**
     * Machine-readable health/metrics. MUST be safe to call at any time and MUST
     * NEVER throw (Driver Standard §7.1).
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array;
}
