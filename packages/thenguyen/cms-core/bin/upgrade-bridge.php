<?php

/**
 * TN CMS Core — upgrade bridge (CORE-UPGRADE-WIN-R1).
 *
 * Runs a Core upgrade with the upgrade ENGINE SHIPPED IN THE TARGET PACKAGE
 * instead of the engine installed on the site. A site on 1.0.0-beta.7.1.34 /
 * .35 carries an engine that replaces whole Core directories by rename, which
 * fails on Windows while any program holds a handle under a Core root ("failed
 * to promote staged dir: app"). Uploading a fixed package through that site's
 * own wizard would still run the OLD engine. This bridge loads the package's
 * engine classes first, then boots the site, so the site's normal upgrade
 * service ("cms.upgrade") is constructed from the fixed code.
 *
 * Trust chain (nothing from the archive executes before it is verified):
 *   archive SHA-256 == the official sidecar / --sha256 value
 *   → tncms-upgrade.json "core_manifest_sha256" == SHA-256(tncms-core.manifest.json)
 *   → every engine file's SHA-256 == its entry in that ownership record.
 *
 * Usage (from any directory, with the site's own PHP CLI):
 *   php upgrade-bridge.php --site=C:\path\to\site --package=C:\path\tncms-X-upgrade.zip [--sha256=HEX]
 *   php upgrade-bridge.php --site=... --package=... --recover=upg_YYYYMMDDHHMMSS_xxxxxxxx
 *   php upgrade-bridge.php --site=... --usage          (upgrade data kept on disk)
 *   php upgrade-bridge.php --site=... --cleanup=<id>   (retry a pending post-commit cleanup)
 *   php upgrade-bridge.php --site=... --purge=<id>     (delete one finished attempt;
 *       refused for the latest successful backup and any attempt awaiting recovery)
 *
 * --usage, --cleanup and --purge need no package: they run on the site's
 * installed upgrade engine (refused when that engine predates them). Given
 * --package, they use the verified package engine instead, as before. An
 * upgrade and --recover always need --package: a site whose upgrade was
 * interrupted may carry a half-replaced engine, so recovery runs the package's.
 *
 * Run it while the web server for the site is stopped or idle. It never prints
 * .env values or absolute paths of the site; output is a bounded step log.
 *
 * Exit codes: 0 committed (verified) · 2 rolled back (verified) ·
 *             3 recovery required · 1 refused / usage / verification error.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

const ENGINE_PREFIX = 'packages/thenguyen/cms-core/src/Upgrade/';

$opts = getopt('', ['site:', 'package:', 'sha256:', 'recover:', 'usage', 'purge:', 'cleanup:', 'help']);
$modes = array_values(array_intersect(['recover', 'usage', 'cleanup', 'purge'], array_keys($opts)));
$withoutPackage = ['usage' => 'storageUsage', 'cleanup' => 'retryCleanup', 'purge' => 'purgeAttempt'];
if (isset($opts['help']) || ! isset($opts['site']) || count($modes) > 1
    || array_filter($opts, 'is_array') !== [] // an option given twice
    || (! isset($opts['package']) && (isset($opts['sha256']) || ! isset($withoutPackage[$modes[0] ?? ''])))) {
    fwrite(STDERR, "usage: php upgrade-bridge.php --site=<site root> --package=<tncms-*-upgrade.zip> [--sha256=<hex>] [--recover=<upgrade id>]\n"
        ."       php upgrade-bridge.php --site=<site root> --usage | --cleanup=<upgrade id> | --purge=<upgrade id>\n");
    exit(1);
}

$out = static function (string $label, bool $ok, string $detail = ''): void {
    printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  — $detail" : '');
};
$fail = static function (string $why) use ($out): never {
    $out($why, false);
    echo "BRIDGE: REFUSED\n";
    exit(1);
};

$site = rtrim(str_replace('\\', '/', (string) realpath((string) $opts['site'])), '/');
$zipPath = isset($opts['package']) ? str_replace('\\', '/', (string) realpath((string) $opts['package'])) : '';
if ($site === '' || ! is_file($site.'/artisan') || ! is_file($site.'/vendor/autoload.php') || ! is_file($site.'/.env')) {
    $fail('site root is not an installed TN CMS site');
}
if (isset($opts['package'])) {
    if ($zipPath === '' || ! is_file($zipPath) || ! class_exists(ZipArchive::class)) {
        $fail('package not found or the zip extension is missing');
    }

    // ---- 1. trust chain ------------------------------------------------------
    // The archive is copied ONCE into a fresh private work area; every later read
    // (hash, engine extraction, the upgrade itself) uses that copy, so the bytes
    // that are verified are exactly the bytes that are installed.
    $work = $site.'/storage/app/upgrades/bridge-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
    if (! @mkdir($work.'/engine', 0700, true)) {
        $fail('cannot create a private work area in protected storage');
    }
    register_shutdown_function(static function () use ($work): void {
        foreach (array_merge(glob($work.'/engine/*.php') ?: [], [$work.'/package.zip']) as $f) {
            @unlink($f);
        }
        @rmdir($work.'/engine');
        @rmdir($work);
    });
    $origin = $zipPath;
    $zipPath = $work.'/package.zip';
    if (! @copy($origin, $zipPath)) {
        $fail('cannot copy the package into the private work area');
    }
    $sha = hash_file('sha256', $zipPath);
    $expected = strtolower(trim((string) ($opts['sha256'] ?? '')));
    $anchor = '--sha256';
    if ($expected === '' && is_file($origin.'.sha256')) {
        $expected = strtolower((string) strtok((string) file_get_contents($origin.'.sha256'), " \t\r\n*"));
        $anchor = 'sidecar';
    }
    if ($expected === '' || ! hash_equals($expected, $sha)) {
        $fail('package SHA-256 does not match the official checksum (pass --sha256 or keep the .sha256 sidecar next to the zip)');
    }
    $out('package checksum ('.$anchor.')', true, $sha);
    if ($anchor === 'sidecar') {
        echo "  WARNING: the checksum came from the .sha256 file next to the zip. That proves integrity, not origin;\n"
            ."           compare it with the value on the official release page, or pass --sha256.\n";
    }

    $zip = new ZipArchive;
    if ($zip->open($zipPath) !== true) {
        $fail('package is not a readable zip');
    }
    $upgradeJson = $zip->getFromName('tncms-upgrade.json');
    $coreJson = $zip->getFromName('tncms-core.manifest.json');
    $upgrade = is_string($upgradeJson) ? json_decode($upgradeJson, true) : null;
    $core = is_string($coreJson) ? json_decode($coreJson, true) : null;
    if (! is_array($upgrade) || ! is_array($core) || ($upgrade['package_type'] ?? null) !== 'core-upgrade'
        || ! hash_equals((string) ($upgrade['core_manifest_sha256'] ?? ''), hash('sha256', (string) $coreJson))) {
        $fail('package manifests are missing or fail their integrity checksum');
    }
    $target = (string) ($upgrade['target_version'] ?? '');
    $out('package manifests', true, 'target '.$target);

    // ---- 2. extract + verify the package's upgrade engine ---------------------
    $engineDir = $work.'/engine';
    $files = is_array($core['files'] ?? null) ? $core['files'] : [];
    $engine = [];
    foreach ($files as $rel => $fileSha) {
        $rel = (string) $rel;
        if (! str_starts_with($rel, ENGINE_PREFIX) || ! str_ends_with($rel, '.php')) {
            continue;
        }
        $name = substr($rel, strlen(ENGINE_PREFIX));
        if (preg_match('/^[A-Za-z0-9]+\.php$/', $name) !== 1) {
            continue; // engine files are flat; never extract a nested or odd path
        }
        $bytes = $zip->getFromName($rel);
        if (! is_string($bytes) || ! hash_equals((string) $fileSha, hash('sha256', $bytes))) {
            $fail("engine file fails its ownership checksum: $name");
        }
        $fh = @fopen($engineDir.'/'.$name, 'xb'); // exclusive create: never write through a pre-planted entry
        if ($fh === false || fwrite($fh, $bytes) !== strlen($bytes) || ! fclose($fh)
            || ! hash_equals((string) $fileSha, (string) hash_file('sha256', $engineDir.'/'.$name))) {
            $fail("engine file could not be written and re-verified: $name");
        }
        $engine[] = $engineDir.'/'.$name;
    }
    $zip->close();
    if (! in_array($engineDir.'/UpgradeTransaction.php', $engine, true) || ! in_array($engineDir.'/UpgradeManager.php', $engine, true)) {
        $fail('this package does not ship the transactional upgrade engine');
    }
    sort($engine);
    foreach ($engine as $file) {
        require_once $file; // defined BEFORE the site autoloader could load the installed (old) engine
    }
    $out('package upgrade engine loaded', true, count($engine).' files verified');
}

// ---- 3. boot the site; with --package its "cms.upgrade" service now uses the package engine ----
chdir($site);
require $site.'/vendor/autoload.php';
$app = require $site.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mgr = app('cms.upgrade');
if (isset($opts['package'])) {
    $engineFile = (new ReflectionClass($mgr))->getFileName();
    if (str_replace('\\', '/', (string) $engineFile) !== $engineDir.'/UpgradeManager.php') {
        $fail('the site did not adopt the package engine');
    }
    $out('site booted with the package engine', true, 'installed Core '.$mgr->currentVersion());
} else {
    // Having the method is not enough: older engines run the cleanup without the upgrade lock.
    if (! defined(get_class($mgr).'::PACKAGE_FREE_MAINTENANCE') || ! method_exists($mgr, $withoutPackage[$modes[0]])) {
        $fail('the installed upgrade engine cannot do this; pass --package=<a fixed upgrade package> to use its engine');
    }
    $out('site booted with its installed upgrade engine', true, 'installed Core '.$mgr->currentVersion());
}

$finish = static function (array $res) use ($out): never {
    $outcome = (string) ($res['outcome'] ?? '');
    $out('outcome: '.$outcome, ($res['ok'] ?? false) === true, (string) ($res['reason'] ?? ''));
    echo match ($outcome) {
        TheNguyen\CMS\Upgrade\UpgradeOutcome::COMMITTED_VERIFIED => "BRIDGE: UPGRADED\n",
        TheNguyen\CMS\Upgrade\UpgradeOutcome::ROLLED_BACK_VERIFIED => "BRIDGE: ROLLED BACK (verified)\n",
        TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED => "BRIDGE: REFUSED (nothing changed)\n",
        default => "BRIDGE: RECOVERY REQUIRED\n",
    };
    exit(match ($outcome) {
        TheNguyen\CMS\Upgrade\UpgradeOutcome::COMMITTED_VERIFIED => 0,
        TheNguyen\CMS\Upgrade\UpgradeOutcome::ROLLED_BACK_VERIFIED => 2,
        TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED => 1,
        default => 3,
    });
};

// ---- recovery mode -------------------------------------------------------
if (isset($opts['recover'])) {
    $id = (string) $opts['recover'];
    if (! TheNguyen\CMS\Upgrade\UpgradeId::isValid($id) || ($mgr->store()->load($id) === null && ! $mgr->store()->isUnreadable($id))) {
        $fail('unknown upgrade id');
    }
    $finish($mgr->manualRecover($id));
}

// ---- storage usage / purge -------------------------------------------------
if (isset($opts['usage'])) {
    $u = $mgr->storageUsage();
    printf("  upgrade data: %d attempt(s), %.1f MiB\n", $u['attempts'], $u['bytes'] / 1048576);
    foreach ($u['per_attempt'] as $aid => $bytes) {
        printf("    %s  %.1f MiB\n", $aid, $bytes / 1048576);
    }
    exit(0);
}
if (isset($opts['cleanup'])) {
    $r = $mgr->retryCleanup((string) $opts['cleanup']);
    $out('cleanup '.preg_replace('/[^\x20-\x7E]/', '?', (string) $opts['cleanup']), $r['cleanup_ok'], $r['cleanup_ok'] ? sprintf('%.1f MiB freed', ($r['freed_bytes'] ?? 0) / 1048576) : implode('; ', $r['pending']));
    if (! $r['cleanup_ok'] && isset($r['error'])) {
        echo '  cause (also kept in the upgrade record): '.preg_replace('/[^\x20-\x7E]/', '?', (string) $r['error'])."\n";
    }
    exit($r['cleanup_ok'] ? 0 : 1);
}
if (isset($opts['purge'])) {
    $r = $mgr->purgeAttempt((string) $opts['purge']);
    $out('purge '.preg_replace('/[^\x20-\x7E]/', '?', (string) $opts['purge']), $r['ok'], $r['ok'] ? sprintf('%.1f MiB freed', ($r['freed_bytes'] ?? 0) / 1048576) : $r['reason']);
    exit($r['ok'] ? 0 : 1);
}

// ---- 4. the standard flow, driven by the package engine -------------------
if (($blocking = $mgr->blockingRecovery()) !== null) {
    $fail("upgrade $blocking still requires recovery; run with --recover=$blocking first");
}
$recv = $mgr->receivePackage($zipPath, static fn (string $dest) => copy($zipPath, $dest));
$id = (string) $recv['id'];
echo "  upgrade id: $id\n";

$verify = $mgr->verifyPackage($id);
$out('package verified for this site', (bool) $verify['ok'], (string) ($verify['reason'] ?? ''));
if (! $verify['ok']) {
    $finish(['ok' => false, 'outcome' => TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED, 'reason' => (string) $verify['reason']]);
}
$pre = $mgr->preflight($id);
$failed = array_map(static fn ($c) => $c['name'], array_filter($pre['checks'], static fn ($c) => $c['required'] && ! $c['ok']));
$out('preflight', (bool) $pre['ok'], implode(', ', $failed));
if (! $pre['ok']) {
    $mgr->discard($id);
    $finish(['ok' => false, 'outcome' => TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED, 'reason' => 'preflight failed']);
}
$backup = $mgr->runBackup($id);
$out('backup created and verified', (bool) $backup['ok'], (string) ($backup['reason'] ?? ''));
if (! $backup['ok']) {
    $finish(['ok' => false, 'outcome' => TheNguyen\CMS\Upgrade\UpgradeOutcome::REJECTED, 'reason' => 'backup failed']);
}

$res = $mgr->apply($id);
$state = $mgr->store()->load($id) ?? [];
echo '  applied: '.json_encode($state['applied'] ?? null, JSON_UNESCAPED_SLASHES)."\n";
if (! ($res['ok'] ?? false)) {
    echo '  recovery: '.implode('; ', (array) ($state['recovery_notes'] ?? []))."\n";
}
$finish($res);
