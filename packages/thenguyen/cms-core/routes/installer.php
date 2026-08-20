<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\InstallController;
use TheNguyen\CMS\Http\Middleware\InstallerSession;
use TheNguyen\CMS\Http\Middleware\RedirectIfInstalled;

/*
|--------------------------------------------------------------------------
| Web Installer Routes (v1.0.0-beta.6)
|--------------------------------------------------------------------------
| First-run wizard at /install. Registered in web.php BEFORE the frontend
| catch-all and OUTSIDE the maintenance gate, so the installer is always
| reachable. InstallerSession forces a file session (DB-free), so CSRF works
| before the database exists. Every step except /finish is blocked once the
| installer is locked (RedirectIfInstalled).
*/
Route::middleware([InstallerSession::class, 'web'])
    ->prefix('install')
    ->name('cms.install.')
    ->group(function (): void {
        // Finish handles its own installed-state logic (shows the finish screen
        // once after a successful install, then "already installed").
        Route::get('/finish', [InstallController::class, 'finish'])->name('finish');

        Route::middleware(RedirectIfInstalled::class)->group(function (): void {
            Route::get('/', [InstallController::class, 'welcome'])->name('welcome');
            Route::get('/requirements', [InstallController::class, 'requirements'])->name('requirements');
            Route::get('/database', [InstallController::class, 'database'])->name('database');
            Route::post('/database', [InstallController::class, 'storeDatabase'])->name('database.store');
            Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
            Route::post('/admin', [InstallController::class, 'storeAdmin'])->name('admin.store');
        });
    });
