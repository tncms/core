<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Translation\Storage\Drivers;

use Illuminate\Support\Facades\DB;
use TheNguyen\CMS\Translation\DTOs\LocalizedValue;
use TheNguyen\CMS\Translation\DTOs\TranslationKey;
use TheNguyen\CMS\Translation\Storage\Contracts\BatchLocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Contracts\LocalizedStorageInterface;
use TheNguyen\CMS\Translation\Storage\Models\LocalizedEntry;

/**
 * The default localized-storage backend: one row per (namespace, key, locale) in
 * `cms_localized_values` (Phase 8.1).
 *
 * Reads are defensive — a missing table or query error resolves to "no value" so
 * a read can never break a page. Writes are atomic (a full {@see put()} replaces
 * the whole locale map inside a transaction) and surface real errors to the
 * caller. No fallback, cache, or validation lives here — those are the resolver's
 * and repository's concerns.
 *
 * Implements {@see BatchLocalizedStorageInterface} (Phase 8.2) so the entity layer
 * can load a whole entity's fields (or many entities) in one query.
 */
final class DatabaseLocalizedStorage implements LocalizedStorageInterface, BatchLocalizedStorageInterface
{
    public function name(): string
    {
        return 'database';
    }

    public function get(TranslationKey $key): LocalizedValue
    {
        try {
            /** @var array<string, string|null> $rows */
            $rows = LocalizedEntry::query()
                ->where('namespace', $this->namespace($key))
                ->where('key', $key->key)
                ->pluck('value', 'locale')
                ->all();

            return new LocalizedValue($rows);
        } catch (\Throwable) {
            return new LocalizedValue([]);
        }
    }

    public function getLocale(TranslationKey $key, string $locale): ?string
    {
        try {
            $value = LocalizedEntry::query()
                ->where('namespace', $this->namespace($key))
                ->where('key', $key->key)
                ->where('locale', $locale)
                ->value('value');

            return is_string($value) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function put(TranslationKey $key, LocalizedValue $value): void
    {
        $namespace = $this->namespace($key);
        $map = $value->toArray();

        DB::transaction(function () use ($key, $namespace, $map): void {
            foreach ($map as $locale => $text) {
                LocalizedEntry::query()->updateOrCreate(
                    ['namespace' => $namespace, 'key' => $key->key, 'locale' => (string) $locale],
                    ['value' => (string) $text],
                );
            }

            // Full-replace semantics: drop any locale no longer present.
            $keep = array_map('strval', array_keys($map));

            LocalizedEntry::query()
                ->where('namespace', $namespace)
                ->where('key', $key->key)
                ->when($keep !== [], fn ($q) => $q->whereNotIn('locale', $keep))
                ->delete();
        });
    }

    public function putLocale(TranslationKey $key, string $locale, ?string $value): void
    {
        if ($value === null) {
            $this->forgetLocale($key, $locale);

            return;
        }

        LocalizedEntry::query()->updateOrCreate(
            ['namespace' => $this->namespace($key), 'key' => $key->key, 'locale' => $locale],
            ['value' => $value],
        );
    }

    public function forget(TranslationKey $key): void
    {
        LocalizedEntry::query()
            ->where('namespace', $this->namespace($key))
            ->where('key', $key->key)
            ->delete();
    }

    public function forgetLocale(TranslationKey $key, string $locale): void
    {
        LocalizedEntry::query()
            ->where('namespace', $this->namespace($key))
            ->where('key', $key->key)
            ->where('locale', $locale)
            ->delete();
    }

    public function exists(TranslationKey $key, ?string $locale = null): bool
    {
        try {
            $query = LocalizedEntry::query()
                ->where('namespace', $this->namespace($key))
                ->where('key', $key->key);

            if ($locale !== null) {
                $query->where('locale', $locale);
            }

            return $query->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function getMany(array $keys): array
    {
        // Seed the result so every requested key is present (empty when unstored).
        $result = [];
        $wanted = [];

        foreach ($keys as $key) {
            if (! $key instanceof TranslationKey) {
                continue;
            }
            $id = $key->toString();
            $result[$id] = new LocalizedValue([]);
            $wanted[$id] = ['namespace' => $this->namespace($key), 'key' => $key->key];
        }

        if ($wanted === []) {
            return $result;
        }

        try {
            /** @var array<string, array<string, string|null>> $rowsByKey */
            $rowsByKey = [];

            LocalizedEntry::query()
                ->where(function ($query) use ($wanted): void {
                    foreach ($wanted as $pair) {
                        $query->orWhere(fn ($q) => $q
                            ->where('namespace', $pair['namespace'])
                            ->where('key', $pair['key']));
                    }
                })
                ->get(['namespace', 'key', 'locale', 'value'])
                ->each(function ($row) use (&$rowsByKey): void {
                    $ns = (string) $row->namespace;
                    $id = $ns !== '' ? $ns.'::'.$row->key : (string) $row->key;
                    $rowsByKey[$id][(string) $row->locale] = $row->value;
                });

            foreach ($rowsByKey as $id => $map) {
                if (array_key_exists($id, $result)) {
                    $result[$id] = new LocalizedValue($map);
                }
            }
        } catch (\Throwable) {
            // Defensive read — keep the seeded empty values.
        }

        return $result;
    }

    /** Null namespace is stored as '' so the unique index stays consistent. */
    private function namespace(TranslationKey $key): string
    {
        return $key->namespace ?? '';
    }
}
