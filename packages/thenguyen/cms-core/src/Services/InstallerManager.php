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
            $contents = $this->applyEnvValues($contents, $values);

            return @file_put_contents($path, $contents) !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return $contents with each key set — replaced in place, or appended when
     * absent. Pure: builds the new env text in memory without touching the
     * filesystem, so the in-place writeEnv() and the atomic commitEnvironment()
     * share one formatting authority.
     *
     * @param  array<string, string>  $values
     */
    private function applyEnvValues(string $contents, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.$this->formatEnvValue((string) $value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
            }
        }

        return $contents;
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Quote only values that genuinely need it (whitespace, comment/quote
        // chars). A bare '=' does NOT require quoting in dotenv — Laravel's own
        // key:generate writes the base64 APP_KEY (with '=' padding) unquoted, so
        // matching that keeps .env conventional and tool-compatible.
        if (preg_match('/[\s#"\']/', $value) === 1) {
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
    // Environment commit (CORE-INSTALLER-2)
    // ---------------------------------------------------------------------

    /**
     * Atomically create the permanent .env at install-commit time and switch the
     * running process to the permanent APP_KEY.
     *
     * This is the ONLY place a permanent .env is created. Before it runs the site
     * has no .env (FRESH); after it succeeds the site is
     * CONFIG_COMMITTED_NOT_INSTALLED (valid .env + permanent key, no install
     * marker). A fresh commit generates the permanent key here; when a .env
     * already exists (a resume after a partial install) its existing key is
     * REUSED and never rotated (§32, §43).
     *
     * @param  array{app_name: string, app_url: string, app_timezone: string, default_language: string, admin_path: string, db: array{host: string, port: string, database: string, username: string, password: string}}  $config
     *
     * @throws \RuntimeException when the atomic write fails (leaves no partial .env).
     */
    public function commitEnvironment(array $config): void
    {
        $envPath = $this->envPath();

        // Resume-safe: reuse an already-committed permanent key, never rotate it.
        $existingKey = $this->existingEnvKey($envPath);
        $key = $existingKey !== '' ? $existingKey : 'base64:'.base64_encode(random_bytes(32));

        $contents = $this->buildEnvContents($config, $key);

        // The atomic move is the commit point; on failure it throws and leaves no
        // partial, secret-bearing .env.
        app(AtomicEnvWriter::class)->write($envPath, $contents);

        // Switch THIS runtime to the permanent key + configuration so migrations
        // and seeders run against the committed environment. The ephemeral key is
        // NOT forgotten yet — finalizeKeyTransition() does that only after the
        // install is fully locked (§17, §35).
        config([
            'app.key' => $key,
            'app.name' => $config['app_name'],
            'app.url' => rtrim($config['app_url'], '/'),
            'app.timezone' => $config['app_timezone'],
            'app.locale' => $config['default_language'],
            'cms.admin_path' => $config['admin_path'],
        ]);
        putenv('APP_KEY='.$key);
        $_ENV['APP_KEY'] = $key;
        $_SERVER['APP_KEY'] = $key;
    }

    /**
     * True when a permanent .env with a non-empty APP_KEY already exists — i.e.
     * the environment has been committed (CONFIG_COMMITTED_NOT_INSTALLED or
     * INSTALLED). NEVER treated as "installed" on its own (§34).
     */
    public function hasCommittedEnv(): bool
    {
        return $this->existingEnvKey($this->envPath()) !== '';
    }

    /**
     * The permanent .env location. Extracted so tests can target a temporary path
     * (the running source test app legitimately has its own dev .env at
     * base_path) — a test seam, never a production bypass.
     */
    protected function envPath(): string
    {
        return base_path('.env');
    }

    /**
     * Finalize the key transition: drop the ephemeral bootstrap key now that the
     * permanent .env is committed AND this runtime holds the permanent key. Called
     * only after the install is fully locked, so a failure mid-install leaves the
     * ephemeral key in place for a safe retry (§35, §43).
     */
    public function finalizeKeyTransition(): void
    {
        if ((string) config('app.key') !== '' && $this->hasCommittedEnv()) {
            $this->bootstrapKey()->forget();
        }
    }

    /**
     * Build the full .env text from the shipped .env.example template with the
     * installer-managed allowlist applied and production-safe defaults (§13).
     *
     * @param  array{app_name: string, app_url: string, app_timezone: string, default_language: string, admin_path: string, db: array{host: string, port: string, database: string, username: string, password: string}}  $config
     */
    private function buildEnvContents(array $config, string $key): string
    {
        $template = base_path('.env.example');
        $base = is_file($template) ? (string) file_get_contents($template) : '';

        $db = $config['db'];

        return $this->applyEnvValues($base, [
            'APP_NAME' => (string) $config['app_name'],
            'APP_ENV' => 'production',
            'APP_KEY' => $key,
            'APP_DEBUG' => 'false',
            'APP_URL' => rtrim((string) $config['app_url'], '/'),
            'APP_LOCALE' => (string) $config['default_language'],
            'APP_TIMEZONE' => (string) $config['app_timezone'],
            'CMS_DEFAULT_LANGUAGE' => (string) $config['default_language'],
            'ADMIN_PATH' => (string) $config['admin_path'],
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
        ]);
    }

    /**
     * Read the APP_KEY from an existing .env by a line scan (no Dotenv boot).
     * Empty string when the file or the key is absent.
     */
    private function existingEnvKey(string $envPath): string
    {
        if (! is_file($envPath)) {
            return '';
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*APP_KEY\s*=\s*(\S.*)$/', $line, $m) === 1) {
                return trim(trim($m[1]), '"\'');
            }
        }

        return '';
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
     * Apply a usable APP_KEY on a pre-install HTTP request WITHOUT writing .env.
     *
     * A fresh shared-hosting extract ships no .env and an empty APP_KEY, so cookie
     * and session encryption would throw MissingAppKeyException before the wizard
     * can render — making the whole zero-CLI flow unreachable. This applies the
     * ephemeral InstallerBootstrapKey (storage/framework/tncms-installer.key) to
     * the running request: a key scoped strictly to the pre-install runtime and
     * never persisted as the permanent APP_KEY (CORE-INSTALLER-2 — no .env exists
     * before the install commit). DB-free and idempotent: a no-op once any key is
     * present.
     */
    public function ensureRuntimeAppKey(): void
    {
        if ((string) config('app.key') !== '') {
            return;
        }

        config(['app.key' => $this->bootstrapKey()->resolve()]);
    }

    public function bootstrapKey(): InstallerBootstrapKey
    {
        return app(InstallerBootstrapKey::class);
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
