<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use TheNguyen\CMS\Database\Seeders\CmsContentSeeder;
use TheNguyen\CMS\Database\Seeders\CmsLanguageSeeder;
use TheNguyen\CMS\Database\Seeders\CmsMenuSeeder;
use TheNguyen\CMS\Database\Seeders\CmsRolePermissionSeeder;
use TheNguyen\CMS\Database\Seeders\CmsSettingsSeeder;
use TheNguyen\CMS\Models\Role;
use TheNguyen\CMS\Support\CmsInfo;

/**
 * Web Installer Core (v1.0.0-beta.6).
 *
 * Drives the first-run web installer at /install: server-requirement checks,
 * .env writing, a guarded database connection test, migration + seeder
 * execution, super-admin creation, and the install lock. Everything is
 * defensive — no password is ever written to a log, a notification, or
 * /cms-health, and a failed step returns a safe message rather than a stack
 * trace to the browser.
 *
 * Install lock: the CMS is considered installed when EITHER the marker file
 * (storage/app/tncms-installed) OR the TN_CMS_INSTALLED env flag is present.
 */
class InstallerManager
{
    private const MARKER_FILE = 'tncms-installed';

    /** Seeders run in dependency order; missing classes are skipped gracefully. */
    private const SEEDERS = [
        CmsLanguageSeeder::class,
        CmsSettingsSeeder::class,
        CmsContentSeeder::class,
        CmsMenuSeeder::class,
        CmsRolePermissionSeeder::class,
    ];

    // ---------------------------------------------------------------------
    // Install lock
    // ---------------------------------------------------------------------

    public function markerPath(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.self::MARKER_FILE);
    }

    /**
     * Installed when the marker file exists OR the env flag is truthy OR the
     * database shows a completed install.
     *
     * The marker file and env flag are the primary, cheap locks. The database
     * check is a backstop: if an attacker deletes the marker AND clears the env
     * flag to re-open the wizard, a reachable, already-populated database still
     * reports the site as installed and the installer stays locked.
     */
    public function isInstalled(): bool
    {
        return $this->isInstalledQuick() || $this->databaseIndicatesInstalled();
    }

    /**
     * Cheap, database-free install check: marker file OR env flag only.
     *
     * Used on the boot hot path (CmsServiceProvider) so a fresh, pre-install
     * boot performs ZERO database queries and can never fail on an unmigrated
     * or unreachable database. markInstalled() writes both the marker and the
     * env flag, so a normally installed site is always detected here even if one
     * of the two is later removed.
     */
    public function isInstalledQuick(): bool
    {
        if (is_file($this->markerPath())) {
            return true;
        }

        $flag = env('TN_CMS_INSTALLED');

        return is_string($flag)
            ? filter_var($flag, FILTER_VALIDATE_BOOLEAN)
            : (bool) $flag;
    }

    /**
     * True when a reachable database already contains an installed CMS (the
     * users table exists and holds at least one account). Any failure — most
     * commonly an unconfigured database during a genuine fresh install — is
     * reported and treated as "not installed" so the wizard can still run.
     */
    private function databaseIndicatesInstalled(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function canRun(): bool
    {
        return ! $this->isInstalled();
    }

    /**
     * Lock the installer: write the marker file AND the env flag. The marker
     * file is the durable signal (survives a cached config); the env flag is a
     * human-visible second lock.
     */
    public function markInstalled(): void
    {
        @file_put_contents(
            $this->markerPath(),
            'TN CMS '.CmsInfo::version().' installed at '.now()->toIso8601String().PHP_EOL,
        );

        $this->writeEnv(['TN_CMS_INSTALLED' => 'true']);
    }

    // ---------------------------------------------------------------------
    // Requirements
    // ---------------------------------------------------------------------

    /**
     * Required PHP extensions. gd/imagick is checked separately (either works).
     *
     * @var array<int, string>
     */
    private const REQUIRED_EXTENSIONS = [
        'openssl', 'pdo', 'pdo_mysql', 'mbstring', 'tokenizer',
        'xml', 'ctype', 'json', 'fileinfo', 'curl', 'zip',
    ];

    /**
     * Server requirement checks for the requirements screen.
     *
     * @return array<int, array{key: string, label: string, required: bool, passed: bool, value: string}>
     */
    public function requirements(): array
    {
        $checks = [];

        $phpOk = version_compare(PHP_VERSION, '8.3.0', '>=');
        $checks[] = $this->check('php', 'PHP >= 8.3', $phpOk, PHP_VERSION);

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = $this->check('ext_'.$ext, 'Extension: '.$ext, extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
        }

        $imageOk = extension_loaded('gd') || extension_loaded('imagick');
        $checks[] = $this->check('ext_image', 'Extension: gd or imagick', $imageOk, $imageOk ? 'loaded' : 'missing');

        $checks[] = $this->check('db_driver', 'Database driver (pdo_mysql)', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'available' : 'missing');

        foreach ($this->writablePaths() as $key => [$label, $path]) {
            $writable = $this->isWritable($path);
            $checks[] = $this->check('writable_'.$key, $label, $writable, $writable ? 'writable' : 'not writable');
        }

        $appUrl = $this->detectAppUrl();
        $checks[] = $this->check('app_url', 'APP_URL detectable', $appUrl !== '', $appUrl !== '' ? $appUrl : 'undetectable');

        return $checks;
    }

    public function requirementsPassed(): bool
    {
        foreach ($this->requirements() as $check) {
            if ($check['required'] && ! $check['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function writablePaths(): array
    {
        return [
            'env' => ['.env / project root', is_file(base_path('.env')) ? base_path('.env') : base_path()],
            'storage' => ['storage/', storage_path()],
            'bootstrap_cache' => ['bootstrap/cache/', base_path('bootstrap/cache')],
            'public' => ['public/', public_path()],
        ];
    }

    private function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    /**
     * Best-effort APP_URL detection from config, then the current request.
     */
    public function detectAppUrl(): string
    {
        $url = trim((string) config('app.url'));

        if ($url !== '' && $url !== 'http://localhost') {
            return rtrim($url, '/');
        }

        try {
            $request = request();

            if ($request !== null && $request->getSchemeAndHttpHost() !== '') {
                return rtrim($request->getSchemeAndHttpHost(), '/');
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return $url !== '' ? rtrim($url, '/') : '';
    }

    /**
     * @return array{key: string, label: string, required: bool, passed: bool, value: string}
     */
    private function check(string $key, string $label, bool $passed, string $value): array
    {
        return ['key' => $key, 'label' => $label, 'required' => true, 'passed' => $passed, 'value' => $value];
    }

    // ---------------------------------------------------------------------
    // .env writing
    // ---------------------------------------------------------------------

    /**
     * Update (or append) keys in the project .env. Values that contain spaces or
     * special characters are double-quoted; an empty value is written bare.
     * Never throws — returns false on failure.
     *
     * @param  array<string, string>  $values
     */
    public function writeEnv(array $values): bool
    {
        $path = base_path('.env');

        try {
            $contents = is_file($path) ? (string) file_get_contents($path) : '';

            foreach ($values as $key => $value) {
                $line = $key.'='.$this->formatEnvValue((string) $value);
                $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

                if (preg_match($pattern, $contents) === 1) {
                    $contents = (string) preg_replace($pattern, $line, $contents, 1);
                } else {
                    $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
                }
            }

            return @file_put_contents($path, $contents) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[\s#"\'=]/', $value) === 1) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }

    // ---------------------------------------------------------------------
    // Database
    // ---------------------------------------------------------------------

    /**
     * Test a MySQL connection without touching the default connection. Returns a
     * friendly, password-free error on failure.
     *
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $cfg
     * @return array{ok: bool, error: ?string}
     */
    public function testDatabaseConnection(array $cfg): array
    {
        $name = '_install_test';

        config(["database.connections.$name" => $this->connectionConfig($cfg)]);
        // Purge any cached instance so the freshly-set config is always used
        // (DB::connection() otherwise reuses a connection with its baked-in config).
        DB::purge($name);

        try {
            DB::connection($name)->getPdo();
            DB::purge($name);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            DB::purge($name);

            return ['ok' => false, 'error' => $this->friendlyDbError($e, $cfg['password'])];
        }
    }

    /**
     * Point the default mysql connection at the supplied config for this request
     * so migrations/seeders run against the new database immediately.
     *
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $cfg
     */
    public function applyRuntimeDatabase(array $cfg): void
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => array_merge(
                (array) config('database.connections.mysql', []),
                $this->connectionConfig($cfg),
            ),
        ]);

        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $cfg
     * @return array<string, mixed>
     */
    private function connectionConfig(array $cfg): array
    {
        return [
            'driver' => 'mysql',
            'host' => $cfg['host'],
            'port' => $cfg['port'],
            'database' => $cfg['database'],
            'username' => $cfg['username'],
            'password' => $cfg['password'],
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /**
     * True when the default database connection has enough configuration to be
     * usable WITHOUT a network round-trip: a SQLite file that exists (or
     * `:memory:`), or a server driver with both a database name and a username.
     *
     * Cheap (config + filesystem only, never a query) and never throws. The boot
     * hot path uses it to decide whether it may trust the install lock and query
     * the database, or must fall back to safe defaults -- so an installed marker
     * left next to an incomplete/empty DB config never crashes the CMS.
     */
    public function hasUsableDatabaseConfig(): bool
    {
        try {
            $default = (string) config('database.default', '');

            if ($default === '') {
                return false;
            }

            $conn = (array) config("database.connections.$default", []);
            $driver = (string) ($conn['driver'] ?? $default);
            $database = trim((string) ($conn['database'] ?? ''));

            if ($driver === 'sqlite') {
                return $database === ':memory:' || ($database !== '' && is_file($database));
            }

            // Server drivers (mysql/mariadb/pgsql/sqlsrv): a database name and a
            // username are the minimum; an empty password is allowed.
            return $database !== '' && trim((string) ($conn['username'] ?? '')) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function friendlyDbError(\Throwable $e, string $password): string
    {
        $message = $e->getMessage();

        if ($password !== '') {
            $message = str_replace($password, '***', $message);
        }

        // Keep it short; the raw driver message never contains the password.
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        if (mb_strlen($message) > 240) {
            $message = mb_substr($message, 0, 240).'…';
        }

        return $message !== '' ? $message : 'Unable to connect to the database.';
    }

    // ---------------------------------------------------------------------
    // Execution
    // ---------------------------------------------------------------------

    /**
     * Generate APP_KEY only when it is missing — avoids invalidating the active
     * installer session cookie mid-flow. Idempotent.
     */
    public function ensureAppKey(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            Artisan::call('key:generate', ['--force' => true]);
        }
    }

    /**
     * Ensure a usable APP_KEY exists on the FIRST pre-install HTTP request.
     *
     * A fresh shared-hosting extract ships no .env and an empty APP_KEY, so cookie
     * and session encryption would throw MissingAppKeyException before the wizard
     * can render — making the whole zero-CLI flow unreachable. This bootstraps .env
     * from .env.example (once), generates a key WITHOUT shelling out to artisan
     * (key:generate needs an existing .env), persists it, and applies it to the
     * running request so the encrypter that resolves later in the middleware stack
     * uses it. DB-free and idempotent: a no-op once a key is present.
     */
    public function ensureRuntimeAppKey(): void
    {
        if ((string) config('app.key') !== '') {
            return;
        }

        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            $example = base_path('.env.example');
            @copy(is_file($example) ? $example : $envPath, $envPath);
            if (! is_file($envPath)) {
                @file_put_contents($envPath, '');
            }
        }

        $key = 'base64:'.base64_encode(random_bytes(32));

        // Persist for subsequent requests (writeEnv replaces the APP_KEY= line
        // seeded by .env.example) and apply to THIS request so the not-yet-resolved
        // encrypter is built with it.
        $this->writeEnv(['APP_KEY' => $key]);
        config(['app.key' => $key]);
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
    }

    /**
     * Run the CMS seeders in order. Missing seeder classes are skipped.
     *
     * @return array<int, string>
     */
    public function runSeeders(): array
    {
        $ran = [];

        foreach (self::SEEDERS as $seeder) {
            if (! class_exists($seeder)) {
                continue;
            }

            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
            $ran[] = $seeder;
        }

        return $ran;
    }

    /**
     * Set the default language (best-effort) when the chosen code differs.
     */
    public function applyDefaultLanguage(string $code): void
    {
        $code = trim($code);

        if ($code === '') {
            return;
        }

        try {
            app('cms.language')->setDefault($code);
        } catch (\Throwable) {
            // Best-effort — the seeded default (vi) remains otherwise.
        }
    }

    public function clearCaches(): void
    {
        Artisan::call('optimize:clear');
    }

    /**
     * Create the first super-admin user and grant the super-admin role. The
     * password is assigned plain and hashed by the model's "hashed" cast.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createSuperAdmin(array $data): object
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = (string) config('auth.providers.users.model', \App\Models\User::class);

        $user = new $model;
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->save();

        $role = Role::query()->where('slug', Role::SUPER_ADMIN)->first();

        if ($role !== null) {
            $role->users()->syncWithoutDetaching([$user->getKey()]);
        }

        return $user;
    }

    public function superAdminExists(): bool
    {
        return app('cms.permission')->superAdminUserCount() > 0;
    }

    // ---------------------------------------------------------------------
    // URLs
    // ---------------------------------------------------------------------

    public function frontendUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/';
    }

    public function adminUrl(): string
    {
        $path = trim((string) config('cms.admin_path', 'admin'), '/');

        return rtrim((string) config('app.url'), '/').'/'.$path;
    }
}
