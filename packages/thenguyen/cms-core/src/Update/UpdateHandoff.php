<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

use TheNguyen\CMS\Upgrade\UpgradeManager;
use Throwable;

/**
 * Verified handoff boundary (CORE-UPGRADE-2, §handoff).
 *
 * The ONLY bridge from the remote update layer into the CORE-UPGRADE-1 upgrade
 * engine. It accepts nothing but a {@see VerifiedUpdatePackage} (whose existence
 * is proof of full verification) and enters it into the engine through the exact
 * seam the manual wizard uses — {@see UpgradeManager::receivePackage()} then
 * {@see UpgradeManager::verifyPackage()}. CORE-UPGRADE-2 stops here: it never
 * runs preflight, backup, apply or any mutation. The engine remains the single
 * authority that applies an upgrade.
 */
final class UpdateHandoff
{
    public function __construct(private UpgradeManager $engine) {}

    /**
     * Enter a verified package into the upgrade engine, leaving a fresh attempt
     * at the VERIFIED state ready for the operator to continue in the /upgrade
     * wizard (system check → backup → apply).
     *
     * @return array{id:string, verify:array<string,mixed>}
     */
    public function toEngine(VerifiedUpdatePackage $package): array
    {
        if (! is_file($package->packagePath)) {
            throw UpdateException::of('handoff_missing_package', 'The verified package is no longer present.');
        }

        // One upgrade at a time — mirror the wizard's guard (§7).
        if ($this->engine->active() !== null) {
            throw UpdateException::of('handoff_upgrade_active', 'An upgrade attempt is already in progress. Finish or discard it first.');
        }

        $recv = $this->engine->receivePackage(
            $package->packagePath,
            static function (string $dest) use ($package): void {
                $dir = \dirname($dest);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                if (! @rename($package->packagePath, $dest) && ! @copy($package->packagePath, $dest)) {
                    throw UpdateException::of('handoff_move_failed', 'Could not move the verified package into upgrade storage.');
                }
            },
        );

        $id = (string) $recv['id'];

        try {
            $verify = $this->engine->verifyPackage($id);
        } catch (Throwable $e) {
            throw UpdateException::of('handoff_engine_verify', 'The upgrade engine could not verify the package: '.$e->getMessage());
        }

        return ['id' => $id, 'verify' => $verify];
    }
}
