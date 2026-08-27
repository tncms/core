<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\RemoteUpdateController;
use TheNguyen\CMS\Http\Controllers\UpgradeController;
use TheNguyen\CMS\Http\Middleware\EnsureUpgradeAccess;

/*
|--------------------------------------------------------------------------
| Manual Core Upgrade Routes (CORE-UPGRADE-1)
|--------------------------------------------------------------------------
| Authenticated Super-Admin wizard at /upgrade. Registered ONLY when the site
| is installed (the inverse of /install, §9) and BEFORE the frontend catch-all
| so /upgrade never resolves as a page slug. Uses the normal "web" group, so
| CSRF + session apply to every state-changing POST (§11); EnsureUpgradeAccess
| enforces installed-state + Super-Admin authorization (§10).
*/
Route::middleware(['web', EnsureUpgradeAccess::class])
    ->prefix('upgrade')
    ->name('cms.upgrade.')
    ->group(function (): void {
        Route::get('/', [UpgradeController::class, 'index'])->name('index');
        Route::post('/package', [UpgradeController::class, 'uploadPackage'])->name('package');

        // CORE-UPGRADE-2: read-only remote update discovery. GET only; no download
        // or execution happens here — it links to the manual wizard above.
        Route::get('/updates', [RemoteUpdateController::class, 'index'])->name('updates');

        Route::get('/system-check', [UpgradeController::class, 'systemCheck'])->name('systemCheck');
        Route::post('/system-check', [UpgradeController::class, 'runSystemCheck'])->name('systemCheck.run');

        Route::get('/backup', [UpgradeController::class, 'backup'])->name('backup');
        Route::post('/backup', [UpgradeController::class, 'runBackup'])->name('backup.run');

        Route::get('/ready', [UpgradeController::class, 'ready'])->name('ready');
        Route::post('/start', [UpgradeController::class, 'start'])->name('start');

        Route::get('/finish', [UpgradeController::class, 'finish'])->name('finish');
        Route::post('/recover', [UpgradeController::class, 'recover'])->name('recover');
    });
