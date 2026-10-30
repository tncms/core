<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use TheNguyen\CMS\Support\Plugin;
use TheNguyen\CMS\Support\PluginActivationResult;

/**
 * The plugin lifecycle orchestrator.
 *
 * Activation is more than flipping a flag: a plugin's database must be installed
 * BEFORE it is marked active, or its dashboard 500s on the first request with a
 * missing-table error. This manager enforces the full flow:
 *
 *   validate → beforeActivate → migrate → seed → verify → mark active → afterActivate
 *
 * Failure policy (never leave a plugin half-installed): if migrations/seeders
 * fail or verification does not pass, the plugin is NOT marked active, the error
 * is recorded for the UI, and a friendly {@see PluginActivationResult} is
 * returned. Activation is idempotent — an already-active plugin is a no-op, and
 * already-applied migrations are never rerun.
 *
 * Deactivation only disables the plugin; it NEVER rolls back migrations (a
 * plugin's data must survive a disable/enable cycle).
 *
 * Lifecycle hook seams (before/after activate, deactivate, upgrade, uninstall)
 * are in place and fire a generic `cms.plugin.*` action so future features can
 * listen. Upgrade/uninstall flows are not implemented yet — only the seams.
 */
class PluginLifecycleManager
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly PluginDatabaseManager $database,
        private readonly PluginInstallationGuard $guard,
    ) {}

    /**
     * Activate a plugin, installing its database first. Idempotent and tolerant:
     * it always returns a result rather than throwing.
     */
    public function activate(string $slug): PluginActivationResult
    {
        $plugin = $this->extensions->findPlugin($slug);

        if ($plugin === null) {
            return PluginActivationResult::invalid($slug, 'The plugin is missing or has an invalid manifest.');
        }

        if ($this->extensions->isPluginActive($slug)) {
            return PluginActivationResult::alreadyActive($slug);
        }

        // Make the plugin's own classes (migrations, seeders, providers) loadable
        // this request, even though it was inactive when the request booted.
        $this->extensions->ensurePluginAutoload($plugin);

        $this->beforeActivate($plugin);

        $install = $this->database->install($plugin);

        if (! $install->ok) {
            // Never leave the plugin half-installed: it stays INACTIVE, and the
            // error is recorded so the UI can offer "View Error" / "Run Installation".
            $this->guard->recordError($slug, $install->error);

            return PluginActivationResult::failed($slug, $install->error, $install->ranMigrations);
        }

        $this->guard->clearError($slug);

        if (! $this->extensions->activatePlugin($slug)) {
            return PluginActivationResult::failed($slug, 'The plugin database installed but the plugin could not be marked active.', $install->ranMigrations);
        }

        $this->markPermissionSyncPending($slug);
        $this->afterActivate($plugin);

        return PluginActivationResult::activated($slug, $install);
    }

    /**
     * Deactivate a plugin. Disables it only — migrations are intentionally left
     * in place (no rollback). Idempotent.
     */
    public function deactivate(string $slug): bool
    {
        $plugin = $this->extensions->findPlugin($slug);

        if ($plugin !== null) {
            $this->beforeDeactivate($plugin);
        }

        $ok = $this->extensions->deactivatePlugin($slug);

        if ($ok && $plugin !== null) {
            $this->afterDeactivate($plugin);
        }

        return $ok;
    }

    /**
     * Re-run installation for an already-discovered plugin without changing its
     * active state — the "Run Installation" action for a plugin whose database
     * install previously failed.
     */
    public function install(string $slug): PluginActivationResult
    {
        $plugin = $this->extensions->findPlugin($slug);

        if ($plugin === null) {
            return PluginActivationResult::invalid($slug, 'The plugin is missing or has an invalid manifest.');
        }

        $this->extensions->ensurePluginAutoload($plugin);

        $result = $this->database->install($plugin);

        if (! $result->ok) {
            $this->guard->recordError($slug, $result->error);

            return PluginActivationResult::failed($slug, $result->error, $result->ranMigrations);
        }

        $this->guard->clearError($slug);
        $this->markPermissionSyncPending($slug);

        return PluginActivationResult::activated($slug, $result);
    }

    // -----------------------------------------------------------------
    // Permission persistence (CORE-STRUCTURED-CONTENT-AUTHORITY-R1)
    // -----------------------------------------------------------------

    private const PERMISSION_SYNC_KEY = 'extensions.permission_sync_pending';

    /**
     * Persist the registered permission definitions of every ACTIVE plugin that
     * was activated, re-activated or re-installed since the last boot. Its
     * provider only registers definitions on a request where it is active, so
     * this runs at boot after providers registered. Idempotent; persists only the
     * plugin's own slugs; never grants, revokes or deletes anything. A plugin is
     * dropped from the pending list only once its persistence succeeded.
     *
     * @return array<string, list<string>> plugin slug => persisted permission slugs
     */
    public function syncPendingPermissions(): array
    {
        $pending = settings(self::PERMISSION_SYNC_KEY, []);

        if (! is_array($pending) || $pending === []) {
            return [];
        }

        /** @var PermissionManager $permissions */
        $permissions = app('cms.permission');
        $done = [];
        $remaining = [];

        foreach (array_unique(array_filter($pending, 'is_string')) as $slug) {
            if (! $this->extensions->isPluginActive($slug)) {
                continue; // deactivated meanwhile: nothing registered, drop it
            }

            try {
                $done[$slug] = $permissions->persistOwnedBy($slug);
            } catch (\Throwable $e) {
                report($e);
                $remaining[] = $slug;
            }
        }

        $this->storePermissionSyncPending($remaining);

        return $done;
    }

    private function markPermissionSyncPending(string $slug): void
    {
        try {
            $pending = settings(self::PERMISSION_SYNC_KEY, []);
            $pending = is_array($pending) ? $pending : [];

            if (! in_array($slug, $pending, true)) {
                $pending[] = $slug;
                $this->storePermissionSyncPending($pending);
            }
        } catch (\Throwable $e) {
            report($e); // never fails an activation; the next activation retries
        }
    }

    /** @param  array<int, string>  $slugs */
    private function storePermissionSyncPending(array $slugs): void
    {
        settings()->set(self::PERMISSION_SYNC_KEY, array_values($slugs), 'array', [
            'is_public' => false,
            'autoload' => true,
            'description' => 'Plugins whose permission definitions await persistence',
        ]);
    }

    // -----------------------------------------------------------------
    // Lifecycle hook seams (architecture only — see class docblock)
    // -----------------------------------------------------------------

    protected function beforeActivate(Plugin $plugin): void
    {
        $this->fireLifecycle('before_activate', $plugin);
    }

    protected function afterActivate(Plugin $plugin): void
    {
        $this->fireLifecycle('after_activate', $plugin);
    }

    protected function beforeDeactivate(Plugin $plugin): void
    {
        $this->fireLifecycle('before_deactivate', $plugin);
    }

    protected function afterDeactivate(Plugin $plugin): void
    {
        $this->fireLifecycle('after_deactivate', $plugin);
    }

    protected function beforeUpgrade(Plugin $plugin): void
    {
        $this->fireLifecycle('before_upgrade', $plugin);
    }

    protected function afterUpgrade(Plugin $plugin): void
    {
        $this->fireLifecycle('after_upgrade', $plugin);
    }

    protected function beforeUninstall(Plugin $plugin): void
    {
        $this->fireLifecycle('before_uninstall', $plugin);
    }

    protected function afterUninstall(Plugin $plugin): void
    {
        $this->fireLifecycle('after_uninstall', $plugin);
    }

    /**
     * Fire a generic lifecycle action (`cms.plugin.<event>`) so future features
     * can hook activation/deactivation/upgrade/uninstall. Guarded + best-effort:
     * a broken listener must never break activation.
     */
    private function fireLifecycle(string $event, Plugin $plugin): void
    {
        if (! function_exists('do_action')) {
            return;
        }

        try {
            do_action('cms.plugin.'.$event, $plugin);
        } catch (\Throwable) {
            // A lifecycle listener must never break the lifecycle itself.
        }
    }
}
