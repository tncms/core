<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Keeps plugin pages safe when a plugin's database was not installed.
 *
 * Activation installs the plugin schema (see {@see PluginLifecycleManager}), but
 * if that ever failed — or a legacy plugin was activated before this system —
 * the plugin's tables may be missing. Plugin dashboards must never 500 with a
 * "base table not found" error; instead they check {@see isInstalled()} and,
 * when incomplete, render a friendly "installation incomplete" panel offering
 * [Run Installation] / [View Error].
 *
 * {@see safe()} is the low-level primitive a page uses to run a DB-touching read
 * without risking a missing-table 500.
 */
class PluginInstallationGuard
{
    /** cms_settings key holding the slug => last-install-error map. */
    public const ERROR_KEY = 'extensions.install_errors';

    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly PluginDatabaseManager $database,
    ) {}

    /**
     * Whether the plugin's database is fully installed. Tolerant: an unknown
     * plugin or any inspection error reports "not installed" rather than throwing.
     */
    public function isInstalled(string $slug): bool
    {
        $plugin = $this->extensions->findPlugin($slug);

        if ($plugin === null) {
            return false;
        }

        try {
            return $this->database->verify($plugin);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The last recorded installation error for a plugin (for the "View Error"
     * action), or null when there is none.
     */
    public function error(string $slug): ?string
    {
        $map = settings(self::ERROR_KEY, []);

        return is_array($map) && is_string($map[$slug] ?? null) && $map[$slug] !== '' ? $map[$slug] : null;
    }

    /**
     * Record the last installation error for a plugin (used by the lifecycle
     * manager on a failed activation). Best-effort; never throws.
     */
    public function recordError(string $slug, ?string $error): void
    {
        try {
            $map = settings(self::ERROR_KEY, []);
            $map = is_array($map) ? $map : [];
            $map[$slug] = (string) ($error ?? 'Plugin installation failed.');
            $this->store($map);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Clear any recorded installation error for a plugin (on a successful
     * install). Best-effort; never throws.
     */
    public function clearError(string $slug): void
    {
        try {
            $map = settings(self::ERROR_KEY, []);

            if (! is_array($map) || ! array_key_exists($slug, $map)) {
                return;
            }

            unset($map[$slug]);
            $this->store($map);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Run a DB-touching callback safely. If the plugin's tables are missing
     * (installation incomplete), the resulting query error is swallowed and
     * $fallback is returned instead of bubbling up as a 500. Non-database errors
     * are not caught here — those are real bugs the caller should see.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    public function safe(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (QueryException $e) {
            Log::warning('TN CMS: guarded plugin query failed (installation may be incomplete): '.$e->getMessage());

            return $fallback;
        }
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function store(array $map): void
    {
        settings()->set(self::ERROR_KEY, $map, 'array', [
            'is_public' => false,
            'autoload' => false,
            'description' => 'Plugin installation errors (slug => message)',
        ]);
    }
}
