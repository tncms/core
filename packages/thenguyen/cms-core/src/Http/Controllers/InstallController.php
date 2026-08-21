<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use TheNguyen\CMS\Services\InstallerManager;

/**
 * Web Installer Core (CORE-INSTALLER-2).
 *
 * A first-run wizard: welcome → requirements → configure (site + database) →
 * admin → review → (commit) → finish. Everything is COLLECTED into the
 * server-side installer session first; NOTHING is persisted to .env until the
 * single, explicit install commit at /install/run. That commit atomically
 * creates the permanent .env with a freshly-generated permanent APP_KEY, then
 * migrates, seeds, creates the super-admin, health-checks and locks the
 * installer. Views are simple Blade (no Filament, no login); all execution is
 * delegated to the InstallerManager so this controller stays transport-only.
 *
 * State model (§34): FRESH (no .env, no marker) → CONFIG_COMMITTED_NOT_INSTALLED
 * (valid .env + permanent key, no marker) → INSTALLED (marker written). .env
 * existence alone never means installed.
 */
class InstallController
{
    private function installer(): InstallerManager
    {
        return app('cms.installer');
    }

    public function welcome(): ViewContract
    {
        return view('install.welcome', ['step' => 1]);
    }

    public function requirements(): ViewContract
    {
        $installer = $this->installer();

        return view('install.requirements', [
            'step' => 2,
            'checks' => $installer->requirements(),
            'passed' => $installer->requirementsPassed(),
        ]);
    }

    public function database(): ViewContract|RedirectResponse
    {
        // Requirements gate (§27): never collect configuration — let alone
        // secrets — until the server can actually run the CMS.
        if (! $this->installer()->requirementsPassed()) {
            return redirect()->route('cms.install.requirements');
        }

        $site = (array) session('install.site', []);
        $db = (array) session('install.db', []);

        return view('install.database', [
            'step' => 3,
            'defaults' => [
                'app_name' => (string) ($site['app_name'] ?? config('app.name', 'TN CMS')),
                'app_url' => (string) ($site['app_url'] ?? $this->installer()->detectAppUrl()),
                'app_timezone' => (string) ($site['app_timezone'] ?? config('app.timezone', 'UTC')),
                'default_language' => (string) ($site['default_language'] ?? 'vi'),
                'admin_path' => (string) ($site['admin_path'] ?? config('cms.admin_path', 'admin')),
                'db_host' => (string) ($db['host'] ?? '127.0.0.1'),
                'db_port' => (string) ($db['port'] ?? '3306'),
                'db_database' => (string) ($db['database'] ?? ''),
                'db_username' => (string) ($db['username'] ?? 'root'),
            ],
        ]);
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:255'],
            'app_url' => ['required', 'url', 'max:255'],
            'app_timezone' => ['required', 'string', 'max:64'],
            'default_language' => ['required', 'string', 'in:vi,en'],
            'admin_path' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-]+$/'],
            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['required', 'string', 'max:255'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:255'],
        ]);

        $dbConfig = [
            'host' => $data['db_host'],
            'port' => (string) $data['db_port'],
            'database' => $data['db_database'],
            'username' => $data['db_username'],
            'password' => (string) ($data['db_password'] ?? ''),
        ];

        // Test the connection WITHOUT persisting anything — no .env write, no
        // permanent connection change (§12, §34).
        $test = $this->installer()->testDatabaseConnection($dbConfig);

        if (! $test['ok']) {
            return back()
                ->withInput($request->except('db_password'))
                ->with('db_error', $test['error']);
        }

        // Collect into the server-side installer session only. Nothing touches
        // .env until the install commit at /install/run.
        session([
            'install.site' => [
                'app_name' => $data['app_name'],
                'app_url' => rtrim($data['app_url'], '/'),
                'app_timezone' => $data['app_timezone'],
                'default_language' => $data['default_language'],
                'admin_path' => $data['admin_path'],
            ],
            'install.db' => $dbConfig,
        ]);

        return redirect()->route('cms.install.admin');
    }

    public function admin(): ViewContract|RedirectResponse
    {
        if (! session()->has('install.db')) {
            return redirect()->route('cms.install.database')
                ->with('db_error', tn_trans('Please configure the database first.'));
        }

        return view('install.admin', ['step' => 4]);
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        if (! session()->has('install.db')) {
            return redirect()->route('cms.install.database')
                ->with('db_error', tn_trans('Please configure the database first.'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // The password lives ONLY in the server-side (file) session until commit —
        // never in .env, a hidden field, a query string, or a log (§29).
        session(['install.admin' => [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]]);

        return redirect()->route('cms.install.review');
    }

    public function review(): ViewContract|RedirectResponse
    {
        $site = session('install.site');
        $db = session('install.db');
        $admin = session('install.admin');

        if (! is_array($site) || ! is_array($db) || ! is_array($admin)) {
            return redirect()->route('cms.install.database')
                ->with('db_error', tn_trans('Please complete the configuration steps first.'));
        }

        // Non-secret summary only — no DB password, no key (§12).
        return view('install.review', [
            'step' => 5,
            'site' => $site,
            'db' => [
                'host' => $db['host'],
                'port' => $db['port'],
                'database' => $db['database'],
                'username' => $db['username'],
            ],
            'admin' => ['name' => $admin['name'], 'email' => $admin['email']],
            'error' => session('install_error'),
        ]);
    }

    /**
     * The single install commit. Atomically creates .env with a permanent key,
     * then runs the database lifecycle and locks the installer.
     */
    public function run(Request $request): RedirectResponse
    {
        $installer = $this->installer();

        $site = session('install.site');
        $db = session('install.db');
        $admin = session('install.admin');

        if (! is_array($site) || ! is_array($db) || ! is_array($admin)) {
            return redirect()->route('cms.install.database')
                ->with('db_error', tn_trans('Please complete the configuration steps first.'));
        }

        $config = [
            'app_name' => (string) $site['app_name'],
            'app_url' => (string) $site['app_url'],
            'app_timezone' => (string) $site['app_timezone'],
            'default_language' => (string) $site['default_language'],
            'admin_path' => (string) $site['admin_path'],
            'db' => $db,
        ];

        // Step 1 — atomic environment commit. On failure NOTHING is persisted:
        // no partial .env, no key, no marker. Safe to retry (§15, §42).
        try {
            $installer->commitEnvironment($config);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('cms.install.review')->with(
                'install_error',
                tn_trans('Could not create the environment file. Check that the project root is writable.'),
            );
        }

        // Step 2 — database lifecycle + lock. If any step here fails the .env and
        // its permanent key are preserved (CONFIG_COMMITTED_NOT_INSTALLED); no
        // marker is written and the key is never rotated on retry (§19, §43).
        try {
            $installer->applyRuntimeDatabase($db);
            $installer->runMigrations();
            $installer->runSeeders();
            $installer->applyDefaultLanguage((string) $site['default_language']);

            if (! $installer->superAdminExists()) {
                $installer->createSuperAdmin($admin);
            }

            $installer->clearCaches();
            $installer->markInstalled();
            $installer->finalizeKeyTransition();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('cms.install.review')->with(
                'install_error',
                tn_trans('Installation failed: :message', ['message' => $e->getMessage()]),
            );
        }

        session()->forget(['install.site', 'install.db', 'install.admin']);

        session()->flash('install.success', true);
        session()->flash('install.frontend_url', $installer->frontendUrl());
        session()->flash('install.admin_url', $installer->adminUrl());

        // Signed redirect so the finish screen recognizes a just-completed install
        // even if the session does not survive the ephemeral→permanent key
        // transition (§17). The signature is created with the permanent key.
        return redirect(URL::temporarySignedRoute('cms.install.finish', now()->addMinutes(30)));
    }

    public function finish(Request $request): ViewContract|RedirectResponse
    {
        $installer = $this->installer();

        $justInstalled = $installer->isInstalled()
            && ($request->hasValidSignature() || session()->get('install.success') === true);

        if ($justInstalled) {
            return view('install.finish', [
                'step' => 6,
                'already' => false,
                'frontend_url' => (string) session('install.frontend_url', $installer->frontendUrl()),
                'admin_url' => (string) session('install.admin_url', $installer->adminUrl()),
            ]);
        }

        if ($installer->isInstalled()) {
            return view('install.finish', [
                'step' => 6,
                'already' => true,
                'frontend_url' => $installer->frontendUrl(),
                'admin_url' => $installer->adminUrl(),
            ]);
        }

        return redirect()->route('cms.install.welcome');
    }
}
