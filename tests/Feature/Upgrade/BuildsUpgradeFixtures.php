<?php

declare(strict_types=1);

namespace Tests\Feature\Upgrade;

use TheNguyen\CMS\Upgrade\CoreOwnership;
use TheNguyen\CMS\Upgrade\UpgradePackage;
use ZipArchive;

/**
 * Shared fixture builders for the upgrade test suite (CORE-UPGRADE-1).
 *
 * A single authority for the synthetic install root, staging tree and signed
 * upgrade package used by both the engine and wizard tests, so the fixture shape
 * is defined once. Requires the using test to expose `$this->tmp` (a temp base
 * dir) and `$this->cleanup` (a list of dirs to remove in tearDown).
 */
trait BuildsUpgradeFixtures
{
    /** @return array<string,mixed> the real Core ownership model */
    protected function model(): array
    {
        return (require base_path('tools/distribution/manifest.php'))['core_ownership'];
    }

    protected function writeFile(string $path, string $contents): void
    {
        @mkdir(\dirname($path), 0775, true);
        file_put_contents($path, $contents);
    }

    protected function cmsInfoSource(string $version): string
    {
        return "<?php\nnamespace TheNguyen\\CMS\\Support;\nfinal class CmsInfo { public const VERSION = '$version'; }\n";
    }

    /** Minimal but representative Core payload used for both live and staging. */
    protected function writeCorePayload(string $root, string $version, string $marker): void
    {
        $this->writeFile($root.'/packages/thenguyen/cms-core/src/Support/CmsInfo.php', $this->cmsInfoSource($version));
        $this->writeFile($root.'/app/Http/Kernel.php', "<?php // core $marker\n");
        $this->writeFile($root.'/config/cms.php', "<?php return ['v' => '$marker'];\n");
        $this->writeFile($root.'/vendor/autoload.php', "<?php // autoload $marker\n");
        $this->writeFile($root.'/vendor/composer/installed.php', "<?php return [];\n");
        $this->writeFile($root.'/public/build/manifest.json', json_encode(['app.js' => ['file' => 'assets/app-'.$marker.'.js']]));
        $this->writeFile($root.'/public/build/assets/app-'.$marker.'.js', "// built $marker");
        $this->writeFile($root.'/public/themes/default/css/app.css', "/* $marker */");
        $this->writeFile($root.'/themes/default/theme.json', json_encode(['slug' => 'default', 'v' => $marker]));
        $this->writeFile($root.'/plugins/hello-world/plugin.json', json_encode(['slug' => 'hello-world', 'v' => $marker]));
        $this->writeFile($root.'/artisan', "#!/usr/bin/env php\n<?php // $marker\n");
        $this->writeFile($root.'/bootstrap/app.php', "<?php // bootstrap $marker\n");
        $this->writeFile($root.'/.htaccess', "# htaccess $marker\n");
        $this->writeFile($root.'/.env.example', "APP_ENV=production\n");
        $this->writeFile($root.'/public/index.php', "<?php // index $marker\n");
        $this->writeFile($root.'/public/.htaccess', "# public $marker\n");
        $this->writeFile($root.'/public/favicon.ico', "ICON$marker");
    }

    /** A live install root (Core payload + preserved site state). */
    protected function buildLiveTree(string $root, string $version, bool $withObsolete = false, bool $withRecordedOwnership = false): void
    {
        $this->writeCorePayload($root, $version, 'v1');
        @mkdir($root.'/tools/distribution', 0775, true);
        copy(base_path('tools/distribution/manifest.php'), $root.'/tools/distribution/manifest.php');

        $this->writeFile($root.'/.env', "APP_KEY=base64:AAAA\nAPP_MARKER=keepme\n");
        $this->writeFile($root.'/storage/app/media/photo.txt', 'a photo');
        $this->writeFile($root.'/storage/logs/.gitignore', "*\n");
        $this->writeFile($root.'/public/uploads/pic.txt', 'uploaded');
        $this->writeFile($root.'/plugins/acme/plugin.json', json_encode(['slug' => 'acme']));
        $this->writeFile($root.'/themes/company/theme.json', json_encode(['slug' => 'company']));
        $this->writeFile($root.'/public/themes/company/style.css', '/* custom */');

        if ($withObsolete) {
            $this->writeFile($root.'/app/Obsolete.php', "<?php // removed next release\n");
        }
        if ($withRecordedOwnership) {
            $files = CoreOwnership::ownedFiles($root, $this->model());
            $this->writeFile($root.'/tncms-core.manifest.json', json_encode([
                'product' => 'TNCMS', 'version' => $version,
                'ownership' => $this->model(), 'files' => $files, 'file_count' => count($files),
            ]));
        }
    }

    /** A verified staging tree (target payload, no obsolete file, with core manifest). */
    protected function buildStagingTree(string $staging, string $version): void
    {
        $this->writeCorePayload($staging, $version, 'v2');
        $files = CoreOwnership::ownedFiles($staging, $this->model());
        $this->writeFile($staging.'/'.UpgradePackage::CORE_MANIFEST, json_encode([
            'product' => 'TNCMS', 'version' => $version,
            'ownership' => $this->model(), 'files' => $files, 'file_count' => count($files),
        ]));
    }

    /** Build a signed, well-formed upgrade package zip. */
    protected function buildPackage(string $destZip, string $version, string $minSource, string $product = 'TNCMS', bool $tamperCore = false): void
    {
        $tree = $this->tmp.'/pkgtree-'.bin2hex(random_bytes(3));
        $this->cleanup[] = $tree;
        $this->writeCorePayload($tree, $version, 'v2');

        $model = $this->model();
        $files = CoreOwnership::ownedFiles($tree, $model);
        $core = json_encode([
            'product' => $product, 'version' => $version,
            'ownership' => $model, 'files' => $files, 'file_count' => count($files),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->writeFile($tree.'/'.UpgradePackage::CORE_MANIFEST, $core);
        $coreSha = hash('sha256', $core);
        if ($tamperCore) {
            file_put_contents($tree.'/'.UpgradePackage::CORE_MANIFEST, $core.'   ');
        }

        $this->writeFile($tree.'/'.UpgradePackage::UPGRADE_MANIFEST, json_encode([
            'product' => $product,
            'package_type' => 'core-upgrade',
            'target_version' => $version,
            'minimum_supported_source_version' => $minSource,
            'php_requirement' => '8.3.0',
            'has_migrations' => false,
            'core_manifest_file' => UpgradePackage::CORE_MANIFEST,
            'core_manifest_sha256' => $coreSha,
        ]));

        $this->zipDir($tree, $destZip);
        $this->rrmdir($tree);
    }

    protected function zipDir(string $dir, string $destZip): void
    {
        @unlink($destZip);
        $zip = new ZipArchive();
        $zip->open($destZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $len = strlen(rtrim(str_replace('\\', '/', $dir), '/')) + 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), substr(str_replace('\\', '/', $f->getPathname()), $len));
            }
        }
        $zip->close();
    }

    protected function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$dir/$e";
            (is_dir($p) && ! is_link($p)) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
