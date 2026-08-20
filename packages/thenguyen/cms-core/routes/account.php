<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\Account\AccountController;
use TheNguyen\CMS\Http\Controllers\Account\AccountPreferenceController;
use TheNguyen\CMS\Http\Controllers\Account\AccountProfileController;
use TheNguyen\CMS\Http\Controllers\Account\AccountSecurityController;
use TheNguyen\CMS\Http\Controllers\Account\AccountSessionController;

/*
|--------------------------------------------------------------------------
| Account Foundation routes (v1.0.0-beta.7.1.15)
|--------------------------------------------------------------------------
| Logged-in frontend account area. Literal /account paths registered before
| the frontend {slug} catch-all so they resolve as account pages. Every route
| runs in the "web" group with cms.auth (login required + session policy) and
| cms.frontend_session (standalone session-policy enforcement). Sensitive POST
| actions (password/email/logout-others) are additionally throttled.
|
| Core owns identity/security/preferences only. Plugins add their own sections
| (Orders, Addresses, Wishlist, …) through the account hooks + the
| cms.account.navigation_items filter — never here.
*/

Route::middleware(['web', 'cms.auth', 'cms.frontend_session'])->group(function (): void {
    Route::get('/account', [AccountController::class, 'dashboard'])->name('cms.account');

    Route::get('/account/profile', [AccountProfileController::class, 'edit'])->name('cms.account.profile');
    Route::post('/account/profile', [AccountProfileController::class, 'update'])->name('cms.account.profile.update');

    Route::get('/account/security', [AccountSecurityController::class, 'edit'])->name('cms.account.security');
    Route::post('/account/security/password', [AccountSecurityController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('cms.account.security.password');
    Route::post('/account/security/email', [AccountSecurityController::class, 'updateEmail'])
        ->middleware('throttle:6,1')
        ->name('cms.account.security.email');

    Route::get('/account/sessions', [AccountSessionController::class, 'index'])->name('cms.account.sessions');
    Route::post('/account/sessions/logout-others', [AccountSessionController::class, 'logoutOthers'])
        ->middleware('throttle:6,1')
        ->name('cms.account.sessions.logout-others');

    Route::get('/account/preferences', [AccountPreferenceController::class, 'edit'])->name('cms.account.preferences');
    Route::post('/account/preferences', [AccountPreferenceController::class, 'update'])->name('cms.account.preferences.update');
});
