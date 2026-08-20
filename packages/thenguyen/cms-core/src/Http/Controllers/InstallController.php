<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use TheNguyen\CMS\Services\InstallerManager;

/**
 * Web Installer Core (v1.0.0-beta.6).
 *
 * A small first-run wizard: welcome → requirements → database/app config →
 * super-admin → finish. The database step writes .env and tests the connection;
 * the admin step runs migrations, seeders, creates the super-admin and locks the
 * installer. Simple Blade views (no Filament, no admin login). All execution is
 * delegated to the InstallerManager so this controller stays transport-only.
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

    public function database(): ViewContract
    {
        return view('install.database', [
            'step' => 3,
            'defaults' => [
                'app_name' => (string) config('app.name', 'TN CMS'),
                'app_url' => $this->installer()->detectAppUrl(),
                'app_timezone' => (string) config('app.timezone', 'Asia/Ho_Chi_Minh'),
                'default_language' => 'vi',
                'admin_path' => (string) config('cms.admin_path', 'admin'),
                'db_host' => '127.0.0.1',
                'db_port' => '3306',
                'db_database' => '',
                'db_username' => 'root',
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

        $test = $this->installer()->testDatabaseConnection($dbConfig);

        if (! $test['ok']) {
            return back()
                ->withInput($request->except('db_password'))
                ->with('db_error', $test['error']);
        }

        $written = $this->installer()->writeEnv([
            'APP_NAME' => $data['app_name'],
            'APP_URL' => rtrim($data['app_url'], '/'),
            'APP_TIMEZONE' => $data['app_timezone'],
            'APP_LOCALE' => $data['default_language'],
            'CMS_DEFAULT_LANGUAGE' => $data['default_language'],
            'ADMIN_PATH' => $data['admin_path'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $dbConfig['host'],
            'DB_PORT' => $dbConfig['port'],
            'DB_DATABASE' => $dbConfig['database'],
            'DB_USERNAME' => $dbConfig['username'],
            'DB_PASSWORD' => $dbConfig['password'],
        ]);

        if (! $written) {
            return back()
                ->withInput($request->except('db_password'))
                ->with('db_error', tn_trans('Could not write the .env file. Check that it is writable.'));
        }

        // Carry the verified DB config + app choices to the execution step. The
        // password lives only in the server-side session, never echoed to the UI.
        session([
            'install.db' => $dbConfig,
            'install.default_language' => $data['default_language'],
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
        $dbConfig = session('install.db');

        if (! is_array($dbConfig)) {
            return redirect()->route('cms.install.database')
                ->with('db_error', tn_trans('Please configure the database first.'));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $installer = $this->installer();

        try {
            $installer->applyRuntimeDatabase($dbConfig);
            $installer->ensureAppKey();
            $installer->runMigrations();
            $installer->runSeeders();
            $installer->applyDefaultLanguage((string) session('install.default_language', 'vi'));
            $installer->createSuperAdmin($data);
            $installer->clearCaches();
            $installer->markInstalled();
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('install_error', tn_trans('Installation failed: :message', ['message' => $e->getMessage()]));
        }

        session()->forget(['install.db', 'install.default_language']);

        session()->flash('install.success', true);
        session()->flash('install.frontend_url', $installer->frontendUrl());
        session()->flash('install.admin_url', $installer->adminUrl());

        return redirect()->route('cms.install.finish');
    }

    public function finish(): ViewContract|RedirectResponse
    {
        $installer = $this->installer();

        if (session()->get('install.success') === true) {
            return view('install.finish', [
                'step' => 5,
                'already' => false,
                'frontend_url' => (string) session('install.frontend_url', $installer->frontendUrl()),
                'admin_url' => (string) session('install.admin_url', $installer->adminUrl()),
            ]);
        }

        if ($installer->isInstalled()) {
            return view('install.finish', [
                'step' => 5,
                'already' => true,
                'frontend_url' => $installer->frontendUrl(),
                'admin_url' => $installer->adminUrl(),
            ]);
        }

        return redirect()->route('cms.install.welcome');
    }
}
