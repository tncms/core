<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Lightweight value object describing a discovered plugin.
 *
 * Built from a plugin's plugin.json by the ExtensionManager — it is not an
 * Eloquent model and is never persisted. The set of active plugin slugs lives
 * in cms_settings under "extensions.active_plugins".
 */
final class Plugin
{
    /**
     * @param  array<int, string>  $providers  Fully-qualified service provider classes.
     * @param  array<string, mixed>  $requires  Version constraints (descriptive).
     * @param  array<int, string>  $filamentResources  Fully-qualified Filament Resource classes (manifest "filament.resources").
     * @param  array<int, string>  $filamentPages  Fully-qualified Filament Page classes (manifest "filament.pages").
     * @param  array<string, mixed>  $database  Manifest "database" block (relative migrations/seeders paths).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $author,
        public readonly string $description,
        public readonly string $path,
        public readonly array $providers = [],
        public readonly array $requires = [],
        public readonly ?string $homepage = null,
        public readonly ?string $authorUri = null,
        public readonly ?string $supportEmail = null,
        public readonly array $filamentResources = [],
        public readonly array $filamentPages = [],
        public readonly array $database = [],
    ) {}

    /** Absolute path to the plugin's PSR-4 source root (src/). */
    public function srcPath(): string
    {
        return $this->path.DIRECTORY_SEPARATOR.'src';
    }

    /** Absolute path to the plugin's web routes file, or null when absent. */
    public function webRoutesFile(): ?string
    {
        $file = $this->path.DIRECTORY_SEPARATOR.'routes'.DIRECTORY_SEPARATOR.'web.php';

        return is_file($file) ? $file : null;
    }

    /** Absolute path to the plugin's views directory, or null when absent. */
    public function viewsPath(): ?string
    {
        $dir = $this->path.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';

        return is_dir($dir) ? $dir : null;
    }

    /** Absolute path to the plugin's migrations directory, or null when absent. */
    public function migrationsPath(): ?string
    {
        $dir = $this->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

        return is_dir($dir) ? $dir : null;
    }

    /**
     * Absolute, existing migration directories for this plugin. Honors the
     * manifest `database.migrations` (a string path or a list of paths, relative
     * to the plugin root); falls back to the conventional `database/migrations`.
     * Only directories that actually exist are returned.
     *
     * @return array<int, string>
     */
    public function databaseMigrationPaths(): array
    {
        return $this->resolveDatabasePaths('migrations', 'database/migrations');
    }

    /**
     * Absolute, existing seeder directories for this plugin. Honors the manifest
     * `database.seeders`; falls back to the conventional `database/seeders`.
     *
     * @return array<int, string>
     */
    public function databaseSeederPaths(): array
    {
        return $this->resolveDatabasePaths('seeders', 'database/seeders');
    }

    /**
     * Resolve a `database.<key>` manifest value (string or list of relative
     * paths) to absolute existing directories, or the conventional fallback.
     *
     * @return array<int, string>
     */
    private function resolveDatabasePaths(string $key, string $fallback): array
    {
        $configured = $this->database[$key] ?? null;

        $relatives = match (true) {
            is_string($configured) && trim($configured) !== '' => [trim($configured)],
            is_array($configured) => array_values(array_filter(array_map(
                static fn ($v): string => is_string($v) ? trim($v) : '',
                $configured,
            ), static fn (string $v): bool => $v !== '')),
            default => [$fallback],
        };

        if ($relatives === []) {
            $relatives = [$fallback];
        }

        $out = [];

        foreach ($relatives as $relative) {
            // Keep paths inside the plugin: ignore any absolute or traversing path.
            if (str_contains($relative, '..') || $this->isAbsolute($relative)) {
                continue;
            }

            $dir = $this->path.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

            if (is_dir($dir)) {
                $out[] = $dir;
            }
        }

        return array_values(array_unique($out));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'author' => $this->author,
            'description' => $this->description,
            'path' => $this->path,
            'providers' => $this->providers,
            'requires' => $this->requires,
            'homepage' => $this->homepage,
            'author_uri' => $this->authorUri,
            'support_email' => $this->supportEmail,
            'filament' => [
                'resources' => $this->filamentResources,
                'pages' => $this->filamentPages,
            ],
            'database' => $this->database,
        ];
    }
}
