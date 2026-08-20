<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use TheNguyen\CMS\Support\Plugin;
use TheNguyen\CMS\Support\PluginInstallationResult;

/**
 * Installs and inspects a plugin's own database.
 *
 * A plugin ships its schema as migrations (and, optionally, seeders) under its
 * own directory. This manager discovers those, runs the pending ones against the
 * shared migration repository (so activation actually creates the plugin's
 * tables), and reports whether the plugin is fully installed. It never rolls
 * anything back — see {@see PluginLifecycleManager} for the activation policy.
 *
 * Discovery honors the manifest `database` block:
 *   { "database": { "migrations": "database/migrations", "seeders": "database/seeders" } }
 * falling back to the conventional `database/migrations` + `database/seeders`.
 *
 * Idempotent: migrations already recorded in the repository are never rerun
 * (Laravel's migrator tracks them by file name), so re-activation is a no-op.
 */
class PluginDatabaseManager
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * Whether the plugin declares any migrations at all (a plugin with no
     * database installs trivially).
     */
    public function hasMigrations(Plugin $plugin): bool
    {
        return $plugin->databaseMigrationPaths() !== [];
    }

    /**
     * Install the plugin's database: run pending migrations, then seeders, then
     * verify nothing is left pending. Never throws — a failure is captured as a
     * {@see PluginInstallationResult} with a readable message (raw exception
     * logged). Order mirrors the lifecycle contract: migrate → seed → verify.
     */
    public function install(Plugin $plugin): PluginInstallationResult
    {
        try {
            $migrations = $this->runMigrations($plugin);
        } catch (\Throwable $e) {
            Log::error('TN CMS: plugin "'.$plugin->slug.'" migration failed: '.$e->getMessage(), ['exception' => $e]);

            return PluginInstallationResult::failure($this->friendly($e));
        }

        try {
            $seeders = $this->runSeeders($plugin);
        } catch (\Throwable $e) {
            Log::error('TN CMS: plugin "'.$plugin->slug.'" seeding failed: '.$e->getMessage(), ['exception' => $e]);

            return PluginInstallationResult::failure($this->friendly($e), $migrations);
        }

        if ($this->pendingMigrations($plugin) !== []) {
            return PluginInstallationResult::failure(
                'Some plugin migrations did not complete. The plugin database is incomplete.',
                $migrations,
            );
        }

        return PluginInstallationResult::success($migrations, $seeders);
    }

    /**
     * Run the plugin's pending migrations and return the migration names that
     * were executed (empty when everything was already applied — idempotent).
     *
     * @return array<int, string>
     */
    public function runMigrations(Plugin $plugin): array
    {
        $paths = $plugin->databaseMigrationPaths();

        if ($paths === []) {
            return [];
        }

        if (! $this->migrator->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }

        // Migrator::run only runs migrations not yet in the repository, so this is
        // safe to call repeatedly.
        return array_values($this->migrator->run($paths));
    }

    /**
     * Run the plugin's seeders (if any) and return the seeder classes executed.
     * Seeders are discovered from the seeder directories; each file is loaded and
     * its Seeder subclass resolved through the container and invoked.
     *
     * @return array<int, string>
     */
    public function runSeeders(Plugin $plugin): array
    {
        $ran = [];

        foreach ($plugin->databaseSeederPaths() as $dir) {
            foreach ($this->seederFiles($dir) as $file) {
                $class = $this->resolveSeederClass($file);

                if ($class === null) {
                    continue;
                }

                $seeder = app($class);
                app()->call([$seeder, 'run']);
                $ran[] = $class;
            }
        }

        return $ran;
    }

    /**
     * Whether the plugin's database is fully installed (no pending migrations).
     */
    public function verify(Plugin $plugin): bool
    {
        return $this->pendingMigrations($plugin) === [];
    }

    /**
     * Migration names discovered for the plugin that have not yet been run.
     *
     * @return array<int, string>
     */
    public function pendingMigrations(Plugin $plugin): array
    {
        $paths = $plugin->databaseMigrationPaths();

        if ($paths === []) {
            return [];
        }

        $files = $this->migrator->getMigrationFiles($paths);

        if (! $this->migrator->repositoryExists()) {
            // Nothing has ever been recorded → everything is pending.
            return array_values(array_keys($files));
        }

        $ran = $this->migrator->getRepository()->getRan();

        return array_values(array_diff(array_keys($files), $ran));
    }

    /**
     * A machine-readable status snapshot for the plugin's database.
     *
     * @return array{has_migrations: bool, installed: bool, pending: array<int, string>}
     */
    public function status(Plugin $plugin): array
    {
        $pending = $this->pendingMigrations($plugin);

        return [
            'has_migrations' => $this->hasMigrations($plugin),
            'installed' => $pending === [],
            'pending' => $pending,
        ];
    }

    /**
     * PHP files inside a seeder directory (non-recursive).
     *
     * @return array<int, string>
     */
    private function seederFiles(string $dir): array
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.php');

        return $files === false ? [] : $files;
    }

    /**
     * Load a seeder file and resolve the Seeder subclass it declares. Falls back
     * to the filename-as-classname convention when the file was already loaded.
     */
    private function resolveSeederClass(string $file): ?string
    {
        $before = get_declared_classes();

        require_once $file;

        foreach (array_reverse(array_diff(get_declared_classes(), $before)) as $class) {
            if (is_subclass_of($class, Seeder::class)) {
                return $class;
            }
        }

        // require_once was a no-op (already loaded): infer from the file name.
        $guess = pathinfo($file, PATHINFO_FILENAME);

        return class_exists($guess) && is_subclass_of($guess, Seeder::class) ? $guess : null;
    }

    /**
     * A short, user-facing reason from an installation exception. The full
     * exception (with any SQL) is logged separately; this stays friendly.
     */
    private function friendly(\Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return 'The plugin database could not be installed.';
        }

        // Keep it to a single, readable line.
        $firstLine = strtok($message, "\n");

        return $firstLine === false ? 'The plugin database could not be installed.' : $firstLine;
    }
}
