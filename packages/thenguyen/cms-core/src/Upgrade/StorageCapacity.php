<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Upgrade;

/**
 * Per-filesystem capacity verdicts (CORE-UPGRADE-DISK-QUOTA-P0-R1).
 *
 * Requirements are grouped by the device of their nearest existing directory,
 * so paths on one filesystem share one budget and paths on different mounts are
 * judged separately. Each filesystem is ENOUGH, INSUFFICIENT or UNKNOWN:
 * `disk_free_space()` returning false (disabled function, open_basedir, missing
 * path) is UNKNOWN — never zero, never success.
 *
 * Limits stated, not hidden: free space is the FILESYSTEM's free space. An
 * account quota (cPanel/DirectAdmin/CloudLinux, NTFS per-user quota) and inode
 * limits are not visible to PHP; they are reported as `unverified` and enforced
 * only by the runtime write checks that fail closed.
 */
final class StorageCapacity
{
    public const ENOUGH = 'ENOUGH';

    public const INSUFFICIENT = 'INSUFFICIENT';

    public const UNKNOWN = 'UNKNOWN';

    /** @var (callable(string):(float|false))|null test seam for disk_free_space */
    private $freeSpace;

    public function __construct(?callable $freeSpace = null)
    {
        $this->freeSpace = $freeSpace;
    }

    /**
     * @param  list<array{purpose:string, path:string, bytes:int}>  $requirements
     * @return array{state:string, detail:string, volumes:list<array{device:?int, paths:list<string>, need:int, free:?int, state:string, reason:string}>, quota:string, inodes:string}
     */
    public function assess(array $requirements): array
    {
        $groups = [];
        foreach ($requirements as $r) {
            $existing = self::nearestExisting($r['path']);
            $dev = $existing !== null && \is_array($st = @stat($existing)) ? (int) $st['dev'] : null;
            $key = $dev === null ? 'unknown:'.$r['path'] : 'dev:'.$dev;
            $groups[$key] ??= ['device' => $dev, 'probe' => $existing, 'paths' => [], 'need' => 0];
            $groups[$key]['paths'][] = $r['purpose'];
            $groups[$key]['need'] += max(0, (int) $r['bytes']);
        }

        $volumes = [];
        $overall = self::ENOUGH;
        foreach ($groups as $g) {
            $free = $g['probe'] !== null ? $this->free($g['probe']) : false;
            if ($free === false || $free === null) {
                $state = self::UNKNOWN;
                $reason = 'free_space_unavailable';
            } elseif ($free < $g['need']) {
                $state = self::INSUFFICIENT;
                $reason = 'free_short';
            } else {
                $state = self::ENOUGH;
                $reason = 'free_ok';
            }
            $volumes[] = ['device' => $g['device'], 'paths' => array_values(array_unique($g['paths'])), 'need' => $g['need'],
                'free' => \is_int($free) ? $free : null, 'state' => $state, 'reason' => $reason];
            if ($state === self::INSUFFICIENT || ($state === self::UNKNOWN && $overall === self::ENOUGH)) {
                $overall = $state === self::INSUFFICIENT ? self::INSUFFICIENT : self::UNKNOWN;
            }
        }

        $parts = array_map(static fn (array $v) => sprintf('%s [%s] need %s free %s', $v['state'], implode('+', $v['paths']),
            self::human($v['need']), $v['free'] === null ? 'unknown' : self::human($v['free'])), $volumes);

        return [
            'state' => $overall,
            'detail' => $overall.': '.implode('; ', $parts).'; account quota and inodes unverified',
            'volumes' => $volumes,
            'quota' => 'unverified',
            'inodes' => 'unverified',
        ];
    }

    /** True when every given path resolves to one filesystem device (null when undeterminable). */
    public static function sameDevice(string ...$paths): ?bool
    {
        $devs = [];
        foreach ($paths as $p) {
            $e = self::nearestExisting($p);
            $st = $e !== null ? @stat($e) : false;
            if (! \is_array($st)) {
                return null;
            }
            $devs[(int) $st['dev']] = true;
        }

        return \count($devs) <= 1;
    }

    private function free(string $path): ?int
    {
        $v = $this->freeSpace !== null ? ($this->freeSpace)($path) : @disk_free_space($path);

        return $v === false || $v === null ? null : (int) $v;
    }

    private static function nearestExisting(string $path): ?string
    {
        $p = rtrim(str_replace('\\', '/', $path), '/');
        for ($i = 0; $i < 64 && $p !== '' && $p !== '.'; $i++) {
            if (file_exists($p)) {
                return $p;
            }
            $parent = \dirname($p);
            if ($parent === $p) {
                break;
            }
            $p = $parent;
        }

        return null;
    }

    private static function human(int $bytes): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < 4) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).$u[$i];
    }
}
