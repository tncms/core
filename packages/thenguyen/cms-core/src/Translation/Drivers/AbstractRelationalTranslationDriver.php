<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Drivers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Translation\Contracts\RelationalTranslationDriverInterface;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationContext;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\DTOs\TranslationRecord;
use TheNguyen\CMS\Translation\DTOs\WriteContext;
use TheNguyen\CMS\Translation\Exceptions\DuplicateTranslationException;
use TheNguyen\CMS\Translation\Exceptions\TranslationDriverException;
use TheNguyen\CMS\Translation\Exceptions\TranslationWriteException;

/**
 * Reusable base for a relational translation driver backed by one per-locale
 * table with a `(foreignKey, locale)` unique index (Phase 9.0B).
 *
 * It owns all the shared, business-free plumbing — connection handling, batch
 * loading (one query, no N+1), record hydration, common validation, transaction
 * helpers, typed error mapping, and diagnostics — so a concrete driver only
 * declares its table, columns, and identity. It knows **nothing** about any
 * business module: no slug generation, no HTML sanitizing, no cache
 * invalidation, no locale detection, no fallback, no domain events. Those belong
 * to higher layers (Driver Standard §1.2/§5.3).
 *
 * Reads are resilient (a store error yields empty + is recorded in diagnostics,
 * never a thrown resolution — §7.3); writes are strict (constraint/DB failures
 * surface as typed exceptions, never swallowed — §6.3/§8).
 */
abstract class AbstractRelationalTranslationDriver implements RelationalTranslationDriverInterface
{
    protected int $reads = 0;

    protected int $batchReads = 0;

    protected int $writes = 0;

    protected int $deletes = 0;

    protected int $queries = 0;

    protected ?string $lastError = null;

    public function __construct(
        protected readonly ?string $connectionName = null,
    ) {}

    // ── subclass hooks: the ONLY domain knowledge ────────────────────────────────

    abstract public function name(): string;

    abstract public function version(): string;

    /** The per-locale table, e.g. 'cms_content_translations'. */
    abstract protected function table(): string;

    /** The owning-entity foreign key column, e.g. 'content_id'. */
    abstract protected function foreignKey(): string;

    /** The locale column, e.g. 'locale'. */
    abstract protected function localeColumn(): string;

    /** The namespace root this driver owns, e.g. 'content'. */
    abstract protected function namespaceRoot(): string;

    /**
     * The writable/readable translatable columns, e.g.
     * ['title','slug','excerpt','content','meta_title', …].
     *
     * @return array<int, string>
     */
    abstract protected function translatableFields(): array;

    // ── identity ─────────────────────────────────────────────────────────────────

    public function supports(string $namespace): bool
    {
        $root = $this->namespaceRoot();

        return $namespace === $root || str_starts_with($namespace, $root.'.');
    }

    public function isWritable(): bool
    {
        return true;
    }

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'read' => true,
            'read_all_locales' => true,
            'batch_read' => true,
            'exists' => true,
            'create' => $this->isWritable(),
            'update' => $this->isWritable(),
            'delete' => $this->isWritable(),
            'batch_write' => $this->isWritable(),
            'transactions' => true,
            'writable' => $this->isWritable(),
        ];
    }

    // ── record reads (resilient; never fallback / never infer a locale) ──────────

    public function read(int|string $entityId, string $locale): ?TranslationRecord
    {
        $this->reads++;

        $row = $this->safe(fn () => $this->baseQuery()
            ->where($this->foreignKey(), $entityId)
            ->where($this->localeColumn(), $locale)
            ->first(), null);

        return $row !== null ? $this->hydrate($entityId, $locale, $row) : null;
    }

    public function readAllLocales(int|string $entityId): array
    {
        $this->reads++;

        $rows = $this->safe(fn () => $this->baseQuery()
            ->where($this->foreignKey(), $entityId)
            ->get(), null);

        if ($rows === null) {
            return [];
        }

        $records = [];
        foreach ($rows as $row) {
            $locale = (string) $row->{$this->localeColumn()};
            $records[$locale] = $this->hydrate($entityId, $locale, $row);
        }

        return $records;
    }

    public function batchRead(array $entityIds): array
    {
        $this->batchReads++;

        $ids = array_values(array_unique($entityIds, SORT_REGULAR));

        // Every requested id must be present in the result, even with no rows.
        $result = [];
        $stringToOriginal = [];
        foreach ($ids as $id) {
            $result[$id] = [];
            $stringToOriginal[(string) $id] = $id;
        }

        if ($ids === []) {
            return [];
        }

        // ONE round-trip across all ids — never a query per key.
        $rows = $this->safe(fn () => $this->baseQuery()
            ->whereIn($this->foreignKey(), $ids)
            ->get(), null);

        if ($rows === null) {
            return $result;
        }

        foreach ($rows as $row) {
            $key = (string) $row->{$this->foreignKey()};
            if (! array_key_exists($key, $stringToOriginal)) {
                continue;
            }
            $original = $stringToOriginal[$key];
            $locale = (string) $row->{$this->localeColumn()};
            $result[$original][$locale] = $this->hydrate($original, $locale, $row);
        }

        return $result;
    }

    public function exists(int|string $entityId, ?string $locale = null): bool
    {
        return (bool) $this->safe(function () use ($entityId, $locale): bool {
            $query = $this->baseQuery()->where($this->foreignKey(), $entityId);

            if ($locale !== null) {
                $query->where($this->localeColumn(), $locale);
            }

            return $query->exists();
        }, false);
    }

    // ── record writes (atomic; strict; typed exceptions) ─────────────────────────

    public function create(TranslationRecord $record, ?WriteContext $context = null): TranslationRecord
    {
        $this->writes++;
        $this->assertValidRecord($record);

        $payload = $this->writePayload($record, forInsert: true);

        $this->runWrite($context ?? WriteContext::default(), function () use ($payload): void {
            try {
                $this->baseQuery()->insert($payload);
            } catch (QueryException $e) {
                throw $this->mapWriteException($e);
            }
        });

        return $record;
    }

    public function update(TranslationRecord $record, ?WriteContext $context = null): TranslationRecord
    {
        $this->writes++;
        $this->assertValidRecord($record);

        $payload = $this->writePayload($record, forInsert: false);

        $this->runWrite($context ?? WriteContext::default(), function () use ($record, $payload): void {
            try {
                $this->baseQuery()
                    ->where($this->foreignKey(), $record->entityId)
                    ->where($this->localeColumn(), $record->locale)
                    ->update($payload);
            } catch (QueryException $e) {
                throw $this->mapWriteException($e);
            }
        });

        return $record;
    }

    public function batchWrite(array $records, ?WriteContext $context = null): void
    {
        if ($records === []) {
            return;
        }

        $this->writes++;

        $rows = [];
        foreach ($records as $record) {
            $this->assertValidRecord($record);
            $rows[] = $this->writePayload($record, forInsert: true);
        }

        $updateColumns = array_merge($this->translatableFields(), ['updated_at']);

        $this->runWrite($context ?? WriteContext::default(), function () use ($rows, $updateColumns): void {
            try {
                // ONE statement: INSERT … ON CONFLICT (foreignKey, locale) DO UPDATE.
                $this->baseQuery()->upsert(
                    $rows,
                    [$this->foreignKey(), $this->localeColumn()],
                    $updateColumns,
                );
            } catch (QueryException $e) {
                throw $this->mapWriteException($e);
            }
        });
    }

    public function delete(int|string $entityId, ?string $locale = null, ?WriteContext $context = null): int
    {
        $this->deletes++;

        $count = 0;
        $this->runWrite($context ?? WriteContext::default(), function () use ($entityId, $locale, &$count): void {
            try {
                $query = $this->baseQuery()->where($this->foreignKey(), $entityId);

                if ($locale !== null) {
                    $query->where($this->localeColumn(), $locale);
                }

                $count = $query->delete();
            } catch (QueryException $e) {
                throw $this->mapWriteException($e);
            }
        });

        return $count;
    }

    // ── engine TranslationDriverInterface bridge (field-key granular) ────────────

    public function get(TranslationKey $key, string $locale, TranslationContext $context): ?string
    {
        [$entityId, $field] = $this->parseKey($key);

        if ($field === null) {
            return null;
        }

        $record = $this->read($entityId, $locale);
        $value = $record?->field($field);

        // Return '' faithfully; only a genuinely absent value is null (§4.2).
        return $value === null ? null : (string) $value;
    }

    public function has(TranslationKey $key, string $locale, TranslationContext $context): bool
    {
        $value = $this->get($key, $locale, $context);

        return $value !== null && $value !== '';
    }

    public function all(TranslationKey $key, TranslationContext $context): LocalizedValue
    {
        [$entityId, $field] = $this->parseKey($key);

        if ($field === null) {
            return new LocalizedValue([]);
        }

        $values = [];
        foreach ($this->readAllLocales($entityId) as $locale => $record) {
            $value = $record->field($field);
            if ($value !== null) {
                $values[$locale] = (string) $value;
            }
        }

        return new LocalizedValue($values);
    }

    // ── diagnostics (never throws) ───────────────────────────────────────────────

    public function diagnostics(): array
    {
        return [
            'name' => $this->name(),
            'version' => $this->version(),
            'entity' => $this->namespaceRoot(),
            'store' => $this->table(),
            'connection' => $this->connectionName ?? (string) config('database.default'),
            'namespaces' => $this->namespaces(),
            'writable' => $this->isWritable(),
            'capabilities' => $this->capabilities(),
            'reachable' => $this->pingSafe(),
            'row_count' => $this->countSafe(),
            'last_error' => $this->lastError,
            'metrics' => [
                'reads' => $this->reads,
                'batch_reads' => $this->batchReads,
                'writes' => $this->writes,
                'deletes' => $this->deletes,
                'queries' => $this->queries,
            ],
        ];
    }

    // ── connection & transaction helpers ─────────────────────────────────────────

    protected function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName);
    }

    protected function baseQuery(): Builder
    {
        return $this->connection()->table($this->table());
    }

    /**
     * Run a write atomically: JOIN the caller's transaction when one is already
     * open (context hint OR a live transaction level), otherwise open our own.
     * Never nests a second commit inside an existing transaction (Standard §5.1).
     */
    protected function runWrite(WriteContext $context, callable $callback): void
    {
        $connection = $this->connection();
        $this->queries++;

        if ($context->inTransaction() || $connection->transactionLevel() > 0) {
            $callback();

            return;
        }

        $connection->transaction($callback);
    }

    // ── internals ────────────────────────────────────────────────────────────────

    /**
     * Reject a record with no identity or with a field that is not a known
     * translatable column — common validation that keeps the driver from ever
     * building a query against an arbitrary/unknown column.
     */
    protected function assertValidRecord(TranslationRecord $record): void
    {
        if ((string) $record->entityId === '') {
            throw new TranslationDriverException('A translation record requires an entity id.');
        }

        if ($record->locale === '') {
            throw new TranslationDriverException('A translation record requires a locale.');
        }

        $allowed = $this->translatableFields();
        foreach (array_keys($record->fields) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new TranslationDriverException(sprintf(
                    'Field "%s" is not a translatable column of %s.',
                    (string) $field,
                    $this->table(),
                ));
            }
        }
    }

    /**
     * Build the column payload for an insert/update. Only fields the record
     * actually carries are written (an absent field is left untouched, not
     * nulled). Timestamps are set here; the driver owns no other columns.
     *
     * @return array<string, mixed>
     */
    protected function writePayload(TranslationRecord $record, bool $forInsert): array
    {
        $now = now();

        $payload = [
            $this->foreignKey() => $record->entityId,
            $this->localeColumn() => $record->locale,
            'updated_at' => $now,
        ];

        if ($forInsert) {
            $payload['created_at'] = $now;
        }

        foreach ($this->translatableFields() as $field) {
            if ($record->hasField($field)) {
                $payload[$field] = $record->fields[$field];
            }
        }

        return $payload;
    }

    protected function hydrate(int|string $entityId, string $locale, object $row): TranslationRecord
    {
        $fields = [];
        foreach ($this->translatableFields() as $field) {
            $fields[$field] = $row->{$field} ?? null;
        }

        return new TranslationRecord($entityId, $locale, $fields);
    }

    /**
     * Interpret an engine key: namespace `{root}.{field}` + `key` = entity id.
     * Returns [entityId, field|null]; field is null for a non-owned / field-less
     * namespace so the bridge safely no-ops.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function parseKey(TranslationKey $key): array
    {
        $entityId = $key->key;
        $namespace = $key->namespace ?? '';
        $root = $this->namespaceRoot();
        $field = null;

        if (str_starts_with($namespace, $root.'.')) {
            $candidate = substr($namespace, strlen($root) + 1);
            if (in_array($candidate, $this->translatableFields(), true)) {
                $field = $candidate;
            }
        }

        return [$entityId, $field];
    }

    /**
     * @return array<int, string>
     */
    protected function namespaces(): array
    {
        $root = $this->namespaceRoot();

        return array_merge(
            [$root],
            array_map(fn (string $field): string => $root.'.'.$field, $this->translatableFields()),
        );
    }

    private function mapWriteException(QueryException $e): TranslationDriverException
    {
        $this->lastError = $e->getMessage();

        if ($this->isUniqueViolation($e)) {
            return new DuplicateTranslationException(
                'Duplicate translation row for '.$this->table().'.',
                0,
                $e,
            );
        }

        return new TranslationWriteException(
            'Write failed for '.$this->table().'.',
            0,
            $e,
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $message = strtolower($e->getMessage());

        return $sqlState === '23000'
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    /**
     * Execute a read, converting any failure into a recorded diagnostic + the
     * supplied default. A driver must never throw during resolution (§7.3).
     */
    private function safe(callable $callback, mixed $default): mixed
    {
        try {
            $this->queries++;

            return $callback();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            report($e);

            return $default;
        }
    }

    private function pingSafe(): bool
    {
        try {
            $this->baseQuery()->limit(1)->exists();

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    private function countSafe(): ?int
    {
        try {
            return (int) $this->baseQuery()->count();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }
    }
}
