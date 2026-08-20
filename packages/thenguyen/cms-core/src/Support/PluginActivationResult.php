<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Support;

/**
 * Outcome of a plugin activation run through the {@see \TheNguyen\CMS\Services\PluginLifecycleManager}.
 *
 * A single, tolerant value the admin UI / CLI can branch on: whether activation
 * succeeded, whether it was already active (idempotent no-op), and, on failure,
 * a friendly message plus the migrations that did run before the failure.
 */
final readonly class PluginActivationResult
{
    /**
     * @param  array<int, string>  $ranMigrations
     * @param  array<int, string>  $ranSeeders
     */
    public function __construct(
        public bool $success,
        public string $slug,
        public string $message,
        public bool $alreadyActive = false,
        public ?string $error = null,
        public array $ranMigrations = [],
        public array $ranSeeders = [],
    ) {}

    public static function activated(string $slug, PluginInstallationResult $install): self
    {
        return new self(
            success: true,
            slug: $slug,
            message: 'Plugin activated and its database is installed.',
            ranMigrations: $install->ranMigrations,
            ranSeeders: $install->ranSeeders,
        );
    }

    public static function alreadyActive(string $slug): self
    {
        return new self(true, $slug, 'Plugin is already active.', alreadyActive: true);
    }

    public static function invalid(string $slug, string $message): self
    {
        return new self(false, $slug, $message, error: $message);
    }

    /**
     * @param  array<int, string>  $ranMigrations
     */
    public static function failed(string $slug, ?string $error, array $ranMigrations = []): self
    {
        $error ??= 'Plugin installation failed.';

        return new self(
            success: false,
            slug: $slug,
            message: 'Plugin installation failed; the plugin remains inactive.',
            error: $error,
            ranMigrations: $ranMigrations,
        );
    }
}
