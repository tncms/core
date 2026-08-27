<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Http\Controllers;

use Illuminate\Contracts\View\View as ViewContract;
use TheNguyen\CMS\Update\UpdateService;

/**
 * Read-only remote update status (CORE-UPGRADE-2, §admin-scope).
 *
 * A single authenticated Super-Admin page that reports the installed version,
 * the configured channel and — when a feed is configured and reachable — the
 * latest release and its verification/compatibility state. It performs NO
 * download and offers NO upgrade execution (§forbidden-scope); when an update is
 * available it merely points the operator at the existing manual /upgrade
 * wizard. Discovery never throws: an unreachable feed renders as "unavailable".
 */
class RemoteUpdateController
{
    private function service(): UpdateService
    {
        return app('cms.update');
    }

    public function index(): ViewContract
    {
        $service = $this->service();
        $availability = $service->check();

        return view('upgrade.updates', [
            'step' => 1,
            'availability' => $availability,
            'channel' => $service->channel()->name,
            'enabled' => $service->isEnabled(),
            'feedConfigured' => $service->feedUrl() !== '',
            'lastState' => $service->lastState(),
        ]);
    }
}
