<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Post-apply health verification (CORE-UPGRADE-1, §39/§40).
 *
 * Runs after promotion + migrations + cache refresh, BEFORE the upgrade is
 * marked completed. A failing health check drives rollback (§41) rather than a
 * false "completed" status (§43).
 *
 * In-process constraint: the request that performs the upgrade already loaded
 * the OLD Core classes, so CmsInfo::VERSION cannot self-report the new version.
 * The target-version check therefore reads the promoted CmsInfo.php FROM DISK
 * (proving the files were replaced) instead of trusting a loaded constant. The
 * live HTTP crawl of /, /admin/login and /search (asset 404 = 0) is performed by
 * the browser certification against fresh workers; here the automated gate uses
 * an on-disk asset-integrity proxy (Vite manifest + referenced build files +
 * published default-theme assets all present).
 *
 * Structural DB checks use the already-loaded framework + the live connection,
 * which are safe. Path-injectable for hermetic testing.
 */
final class UpgradeHealthCheck
{
    private string $root;

    /** Core tables that must exist for a healthy install (real CMS schema names). */
    private const CORE_TABLES = ['users', 'migrations', 'cms_settings'];

    public function __construct(?string $root = null)
    {
        $base = $root ?? (\function_exists('base_path') ? base_path() : getcwd());
        $this->root = rtrim(str_replace('\\', '/', (string) $base), '/');
    }

    /**
     * @param  bool  $checkDatabase  run live DB checks (skip in pure-filesystem tests)
     * @return array{ok:bool, version:?string, checks:array<int,array{name:string,ok:bool,detail:string}>}
     */
    public function run(string $expectedVersion, bool $checkDatabase = true): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        };

        // 1) Target Core version — read from the promoted file on disk.
        $version = $this->versionOnDisk();
        $verOk = $version !== null && $version === $expectedVersion;
        $add('version:target-on-disk', $verOk, "disk=$version expected=$expectedVersion");

        // 2) Core boot files present.
        foreach (['artisan', 'bootstrap/app.php', 'vendor/autoload.php'] as $rel) {
            $add("boot:$rel", is_file($this->root.'/'.$rel), $rel);
        }

        // 3) Assets on disk (404 = 0 proxy).
        $this->assetChecks($add);

        // 4) Default theme present.
        $add('theme:default-present', is_dir($this->root.'/themes/default'), 'themes/default');

        // 5) Live database (optional).
        if ($checkDatabase) {
            $this->databaseChecks($add);
        }

        $ok = ! \in_array(false, array_column($checks, 'ok'), true);

        return ['ok' => $ok, 'version' => $version, 'checks' => $checks];
    }

    /** Parse VERSION from the promoted CmsInfo.php on disk. */
    public function versionOnDisk(): ?string
    {
        $file = $this->root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php';
        if (! is_file($file)) {
            return null;
        }
        if (preg_match("/const\s+VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($file), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** @param callable(string,bool,string):void $add */
    private function assetChecks(callable $add): void
    {
        $manifest = $this->root.'/public/build/manifest.json';
        $manifestOk = is_file($manifest);
        $add('assets:vite-manifest', $manifestOk, 'public/build/manifest.json');

        if ($manifestOk) {
            $data = json_decode((string) file_get_contents($manifest), true);
            $missing = [];
            if (\is_array($data)) {
                foreach ($data as $entry) {
                    $f = \is_array($entry) ? ($entry['file'] ?? null) : null;
                    if (\is_string($f) && ! is_file($this->root.'/public/build/'.$f)) {
                        $missing[] = $f;
                    }
                }
            }
            $add('assets:vite-files-present', $missing === [], implode(', ', \array_slice($missing, 0, 10)));
        }

        // Published default-theme physical assets (theme_asset() delivery path).
        $themeDir = $this->root.'/public/themes/default';
        $add('assets:default-theme-published', is_dir($themeDir) && (glob($themeDir.'/*') !== []), 'public/themes/default');
    }

    /** @param callable(string,bool,string):void $add */
    private function databaseChecks(callable $add): void
    {
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $add('db:connection', true);
        } catch (\Throwable $e) {
            $add('db:connection', false, 'no database connection');

            return;
        }

        foreach (self::CORE_TABLES as $table) {
            try {
                $ok = \Illuminate\Support\Facades\Schema::hasTable($table);
            } catch (\Throwable) {
                $ok = false;
            }
            $add("db:table:$table", $ok, $table);
        }
    }
}
