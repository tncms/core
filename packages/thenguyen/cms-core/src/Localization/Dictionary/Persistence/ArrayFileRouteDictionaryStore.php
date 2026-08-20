<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Localization\Dictionary\Persistence;

/**
 * P6.1 — the canonical Route Dictionary store: a plain PHP array file.
 *
 * Chosen storage (Phase A): a returned-array PHP file. It is opcache-cached (no per-request
 * parse), `config:cache`-friendly, human-editable, plugin-mergeable, and needs no database or
 * migration — the right foundation before Dictionary Administration. The store is READ ONCE at
 * boot by the loader; it is never queried during a request.
 *
 * `load()` returns `[]` when the file is absent, so the loader falls back to the frozen seed and
 * the Runtime stays byte-identical on a fresh install.
 */
final class ArrayFileRouteDictionaryStore implements RouteDictionaryStoreInterface
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function load(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $data = require $this->path;

        return is_array($data) ? $data : [];
    }

    public function save(array $data): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        file_put_contents(
            $this->path,
            "<?php\n\ndeclare(strict_types=1);\n\n// Platform Route Dictionary (persisted). key => [locale => segment].\n\nreturn ".var_export($data, true).";\n",
            LOCK_EX,
        );
    }

    public function locales(): array
    {
        $locales = [];

        foreach ($this->load() as $localeMap) {
            if (is_array($localeMap)) {
                foreach (array_keys($localeMap) as $locale) {
                    $locales[(string) $locale] = true;
                }
            }
        }

        return array_keys($locales);
    }

    public function keys(): array
    {
        return array_map('strval', array_keys($this->load()));
    }
}
