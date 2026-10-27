<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use TheNguyen\CMS\Upgrade\UpgradeManager;
use TheNguyen\CMS\Upgrade\UpgradeState;

/**
 * Manual Core upgrade wizard (CORE-UPGRADE-1, §8).
 *
 * A small authenticated Super-Admin wizard at /upgrade:
 *   Package → System Check → Backup → Ready → Upgrade → Finish.
 *
 * Transport-only: every operation is delegated to the UpgradeManager
 * (`cms.upgrade`), which owns the durable state machine, lock, hard backup gate
 * and apply/recovery engine. The controller never advances a settled state by
 * itself — the manager rejects illegal/duplicate transitions (§7). "Start
 * Upgrade" is reachable only once the state carries a verified backup (§20).
 */
class UpgradeController
{
    private function manager(): UpgradeManager
    {
        return app('cms.upgrade');
    }

    /** Landing: current version, a fresh package form, or an interrupted attempt (§44). */
    public function index(): ViewContract
    {
        $mgr = $this->manager();

        return view('upgrade.index', [
            'step' => 1,
            'current' => $mgr->currentVersion(),
            'situation' => $mgr->situation(),
            'latest' => $mgr->latest(),
            'usage' => $mgr->storageUsage(),
            'stepRoute' => fn (string $status) => $this->routeForStatus($status),
        ]);
    }

    /** Receive an uploaded package, store it protected, verify it (§13/§14). */
    public function uploadPackage(Request $request): RedirectResponse
    {
        $mgr = $this->manager();

        // One upgrade at a time: an in-flight attempt must be finished/discarded.
        if ($mgr->active() !== null) {
            return redirect()->route('cms.upgrade.index')
                ->with('upgrade_error', tn_trans('An upgrade is already in progress. Continue or discard it first.'));
        }

        $request->validate([
            'package' => ['required', 'file', 'max:1048576'], // KB → up to ~1 GiB
        ]);

        $file = $request->file('package');
        if (strtolower((string) $file->getClientOriginalExtension()) !== 'zip') {
            return back()->with('upgrade_error', tn_trans('Only .zip upgrade packages are accepted.'));
        }

        $tmpPath = $file->getRealPath() ?: $file->getPathname();
        $recv = $mgr->receivePackage(
            $tmpPath,
            static fn (string $dest) => $file->move(\dirname($dest), \basename($dest)),
        );
        $id = $recv['id'];

        $verify = $mgr->verifyPackage($id);
        if (! $verify['ok']) {
            return redirect()->route('cms.upgrade.index')
                ->with('upgrade_error', $verify['reason']);
        }

        return redirect()->route('cms.upgrade.systemCheck');
    }

    /** System check screen (§15/§16). */
    public function systemCheck(): ViewContract|RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            return redirect()->route('cms.upgrade.index');
        }
        $mgr = $this->manager();
        $pre = $mgr->preflight($id);

        return view('upgrade.system-check', [
            'step' => 2,
            'checks' => $pre['checks'],
            'passed' => $pre['ok'],
            'state' => $mgr->store()->load($id),
        ]);
    }

    /** Re-run the system check; advance to Backup when it passes. */
    public function runSystemCheck(): RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            return redirect()->route('cms.upgrade.index');
        }
        $pre = $this->manager()->preflight($id);

        return $pre['ok']
            ? redirect()->route('cms.upgrade.backup')
            : redirect()->route('cms.upgrade.systemCheck')
                ->with('upgrade_error', tn_trans('Resolve the failed checks, then run the system check again.'));
    }

    /** Backup screen: shows the Start Backup action (§17). */
    public function backup(): ViewContract|RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            return redirect()->route('cms.upgrade.index');
        }
        $state = $this->manager()->store()->load($id);
        $status = (string) ($state['status'] ?? '');
        if (UpgradeState::hasVerifiedBackup($status)) {
            return redirect()->route('cms.upgrade.ready');
        }

        return view('upgrade.backup', ['step' => 3, 'state' => $state]);
    }

    /** Create AND verify the full backup — the hard gate to apply (§20/§21). */
    public function runBackup(): RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            return redirect()->route('cms.upgrade.index');
        }
        $res = $this->manager()->runBackup($id);

        return $res['ok']
            ? redirect()->route('cms.upgrade.ready')
            : redirect()->route('cms.upgrade.backup')->with('upgrade_error', $res['reason']);
    }

    /** Ready screen: Start Upgrade is only usable with a verified backup (§20). */
    public function ready(): ViewContract|RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            return redirect()->route('cms.upgrade.index');
        }
        $state = $this->manager()->store()->load($id);
        $status = (string) ($state['status'] ?? '');
        if (! UpgradeState::hasVerifiedBackup($status)) {
            return redirect()->route($this->routeForStatus($status));
        }

        return view('upgrade.ready', ['step' => 4, 'state' => $state]);
    }

    /** Execute the upgrade (§24). Idempotent: the manager rejects a repeat. */
    public function start(): RedirectResponse
    {
        $id = $this->activeId();
        if ($id === null) {
            // Perhaps just completed — show the finish screen.
            return redirect()->route('cms.upgrade.finish');
        }
        $this->manager()->apply($id);

        return redirect()->route('cms.upgrade.finish');
    }

    /** Finish: completed or failed (with recovery result). */
    public function finish(): ViewContract
    {
        $mgr = $this->manager();
        // The same decision the landing page shows: an attempt that needs recovery outranks everything.
        $situation = $mgr->situation();

        return view('upgrade.finish', [
            'step' => 5,
            'state' => $mgr->latest(),
            'recovery' => $situation['recommendation'] === 'recover' ? $situation['state'] : null,
            'current' => $mgr->currentVersion(),
        ]);
    }

    /** Recover / discard an interrupted or failed attempt (§44). */
    public function recover(): RedirectResponse
    {
        $mgr = $this->manager();
        $state = $mgr->latest();
        if ($state === null) {
            return redirect()->route('cms.upgrade.index');
        }
        // An unverified earlier recovery is always the one to retry first.
        $id = $mgr->blockingRecovery() ?? (string) $state['upgrade_id'];
        $state = $mgr->store()->load($id) ?? $state;
        $status = (string) ($state['status'] ?? '');

        // The manager decides server-side: a COMPLETED (or committed) upgrade is
        // never rolled back from this route, whatever the form posts.
        if ($mgr->needsRecovery($id)) {
            $mgr->manualRecover($id);
        } elseif (! UpgradeState::isTerminal($status)) {
            $discarded = $mgr->discard($id);
            if (! $discarded['ok']) {
                return redirect()->route('cms.upgrade.finish')
                    ->with('upgrade_error', tn_trans('The upgrade was not discarded: :reason', ['reason' => $discarded['reason']]));
            }
        }

        return redirect()->route('cms.upgrade.finish');
    }

    /**
     * Retry the pending post-commit cleanup of a completed upgrade. The manager
     * re-checks eligibility under the upgrade lock, whatever the page showed.
     */
    public function retryCleanup(Request $request): RedirectResponse
    {
        $id = $request->input('upgrade_id');
        $res = $this->manager()->retryCleanup(\is_string($id) ? $id : '');

        if ($res['cleanup_ok']) {
            return redirect()->route('cms.upgrade.finish')
                ->with('upgrade_ok', tn_trans('The temporary upgrade files were removed.'));
        }

        return redirect()->route('cms.upgrade.finish')->with('upgrade_error', ($res['refused'] ?? false)
            ? tn_trans('The cleanup was not run: :reason', ['reason' => (string) $res['reason']])
            : tn_trans('Some temporary upgrade files could not be removed yet: :items', ['items' => implode('; ', $res['pending'])]));
    }

    // ---- helpers --------------------------------------------------------

    private function activeId(): ?string
    {
        $active = $this->manager()->active();

        return $active !== null ? (string) $active['upgrade_id'] : null;
    }

    /** Map a persisted status to the wizard step that owns it. */
    private function routeForStatus(string $status): string
    {
        return match (true) {
            $status === UpgradeState::UPLOADED => 'cms.upgrade.index',
            $status === UpgradeState::VERIFIED => 'cms.upgrade.systemCheck',
            $status === UpgradeState::PREFLIGHT_PASSED => 'cms.upgrade.backup',
            UpgradeState::hasVerifiedBackup($status) && $status !== UpgradeState::COMPLETED => 'cms.upgrade.ready',
            default => 'cms.upgrade.finish',
        };
    }
}
