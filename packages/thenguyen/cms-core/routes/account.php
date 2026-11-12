<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\Account\AccountController;
use TheNguyen\CMS\Http\Controllers\Account\AccountMediaController;
use TheNguyen\CMS\Http\Controllers\Account\AccountPreferenceController;
use TheNguyen\CMS\Http\Controllers\Account\AccountProfileController;
use TheNguyen\CMS\Http\Controllers\Account\AccountProfileFieldsController;
use TheNguyen\CMS\Http\Controllers\Account\AccountSecurityController;
use TheNguyen\CMS\Http\Controllers\Account\AccountSessionController;
use TheNguyen\CMS\Services\LanguageManager;

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
|
| CORE-40-ACCOUNT-SHELL-LOCALE-R1: registered twice, like routes/auth.php — the
| canonical unprefixed paths under their original names, and a {locale}-prefixed
| twin per ACTIVE language named "<name>.localized" (/en/account/profile →
| cms.account.profile.localized) behind cms.canonical-locale. cms.locale runs
| BEFORE cms.auth so a guest redirect to the login page keeps the locale.
*/

$cmsAccountRoutes = static function (string $suffix): void {
    Route::get('/account', [AccountController::class, 'dashboard'])->name('cms.account'.$suffix);

    Route::get('/account/profile', [AccountProfileController::class, 'edit'])->name('cms.account.profile'.$suffix);
    Route::post('/account/profile', [AccountProfileController::class, 'update'])->name('cms.account.profile.update'.$suffix);

    // Dynamic custom profile fields (P3) — separate namespaced owner-only write,
    // never mixed with the base User-column update above.
    Route::post('/account/profile/fields', [AccountProfileFieldsController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('cms.account.profile.fields.update'.$suffix);

    Route::get('/account/security', [AccountSecurityController::class, 'edit'])->name('cms.account.security'.$suffix);
    Route::post('/account/security/password', [AccountSecurityController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('cms.account.security.password'.$suffix);
    Route::post('/account/security/email', [AccountSecurityController::class, 'updateEmail'])
        ->middleware('throttle:6,1')
        ->name('cms.account.security.email'.$suffix);

    Route::get('/account/sessions', [AccountSessionController::class, 'index'])->name('cms.account.sessions'.$suffix);
    Route::post('/account/sessions/logout-others', [AccountSessionController::class, 'logoutOthers'])
        ->middleware('throttle:6,1')
        ->name('cms.account.sessions.logout-others'.$suffix);

    Route::get('/account/preferences', [AccountPreferenceController::class, 'edit'])->name('cms.account.preferences'.$suffix);
    Route::post('/account/preferences', [AccountPreferenceController::class, 'update'])->name('cms.account.preferences.update'.$suffix);

    // Owner-scoped frontend media JSON API (CORE-40-FRONTEND-MEDIA-R1). Permission checks
    // (media.view_own / media.upload_own / media.delete_own) are enforced by app('cms.media.frontend');
    // the limiters are keyed by the signed-in user and, as a second ceiling, by client IP (CORE-P12-1).
    Route::get('/account/media', [AccountMediaController::class, 'index'])
        ->middleware('throttle:cms-frontend-media-list')
        ->name('cms.account.media.index'.$suffix);
    Route::post('/account/media', [AccountMediaController::class, 'store'])
        ->middleware('throttle:cms-frontend-media-upload')
        ->name('cms.account.media.store'.$suffix);
    Route::delete('/account/media/{media}', [AccountMediaController::class, 'destroy'])
        ->middleware('throttle:cms-frontend-media-delete')
        ->name('cms.account.media.destroy'.$suffix);
};

Route::middleware(['web', 'cms.locale', 'cms.auth', 'cms.frontend_session'])->group(static fn () => $cmsAccountRoutes(''));

Route::middleware(['web', 'cms.canonical-locale', 'cms.locale', 'cms.auth', 'cms.frontend_session'])
    ->prefix('{locale}')
    ->whereIn('locale', explode('|', LanguageManager::routeLocalePattern()))
    ->group(static fn () => $cmsAccountRoutes('.localized'));
