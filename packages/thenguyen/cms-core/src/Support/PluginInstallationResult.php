<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Outcome of installing a plugin's database (migrations + seeders).
 *
 * Always returned by {@see \TheNguyen\CMS\Services\PluginDatabaseManager::install()}
 * — success or failure — so the lifecycle manager never has to catch. On failure
 * {@see $ok} is false and {@see $error} carries a readable message (the raw
 * exception is logged separately, never swallowed silently).
 */
final readonly class PluginInstallationResult
{
    /**
     * @param  array<int, string>  $ranMigrations  Migration names executed this run (empty when already installed).
     * @param  array<int, string>  $ranSeeders  Seeder classes executed this run.
     */
    public function __construct(
        public bool $ok,
        public array $ranMigrations = [],
        public array $ranSeeders = [],
        public ?string $error = null,
    ) {}

    /**
     * @param  array<int, string>  $migrations
     * @param  array<int, string>  $seeders
     */
    public static function success(array $migrations = [], array $seeders = []): self
    {
        return new self(true, $migrations, $seeders, null);
    }

    /**
     * @param  array<int, string>  $migrations
     */
    public static function failure(string $error, array $migrations = []): self
    {
        return new self(false, $migrations, [], $error);
    }
}
