<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendEmailVerificationController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendLoginController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendPasswordResetController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendRegisterController;
use TheNguyen\CMS\Services\LanguageManager;

/*
|--------------------------------------------------------------------------
| Frontend Authentication routes (v1.0.0-beta.7.1.14)
|--------------------------------------------------------------------------
| Literal paths registered before the frontend {slug} catch-all so /login,
| /register, etc. resolve as auth pages. All routes run in the "web" group for
| session + CSRF. Framework-standard names (password.reset / verification.verify)
| are used so Laravel's built-in reset/verification notifications resolve with
| no extra wiring.
|
| CORE-40-ACCOUNT-SHELL-LOCALE-R1: every route is registered twice — the
| canonical unprefixed path under its original name (unchanged), and a
| {locale}-prefixed twin for each ACTIVE language under the same name suffixed
| ".localized" (/en/login → cms.auth.login.localized). Both run Core's locale
| runtime (cms.locale); the twin also runs cms.canonical-locale, which 301s a
| duplicate default-locale prefix (/vi/login → /login). An inactive or unknown
| prefix never matches here. Build URLs with cms_localized_route(), never a
| hardcoded prefix. The POST endpoints are throttled by the named cms-auth-*
| limiters (identity + IP keyed, see CmsServiceProvider::registerAuthRateLimiters()).
*/

$cmsAuthRoutes = static function (string $suffix): void {
    // Guest-only pages.
    Route::middleware('cms.guest')->group(static function () use ($suffix): void {
        Route::get('/login', [FrontendLoginController::class, 'show'])->name('cms.auth.login'.$suffix);
        Route::post('/login', [FrontendLoginController::class, 'login'])
            ->middleware('throttle:cms-auth-login')
            ->name('cms.auth.login.attempt'.$suffix);

        Route::get('/register', [FrontendRegisterController::class, 'show'])->name('cms.auth.register'.$suffix);
        Route::post('/register', [FrontendRegisterController::class, 'register'])
            ->middleware('throttle:cms-auth-register')
            ->name('cms.auth.register.store'.$suffix);

        Route::get('/forgot-password', [FrontendPasswordResetController::class, 'showForgot'])->name('password.request'.$suffix);
        Route::post('/forgot-password', [FrontendPasswordResetController::class, 'sendResetLink'])
            ->middleware('throttle:cms-auth-password-email')
            ->name('password.email'.$suffix);

        Route::get('/reset-password/{token}', [FrontendPasswordResetController::class, 'showReset'])->name('password.reset'.$suffix);
        Route::post('/reset-password', [FrontendPasswordResetController::class, 'reset'])
            ->middleware('throttle:cms-auth-password-reset')
            ->name('password.update'.$suffix);
    });

    // Session teardown.
    Route::post('/logout', [FrontendLoginController::class, 'logout'])
        ->middleware('cms.auth')
        ->name('cms.auth.logout'.$suffix);

    // Email verification.
    Route::get('/email/verify', [FrontendEmailVerificationController::class, 'notice'])
        ->middleware('cms.auth')
        ->name('cms.auth.verification.notice'.$suffix);

    Route::get('/email/verify/{id}/{hash}', [FrontendEmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify'.$suffix);

    Route::post('/email/verification-notification', [FrontendEmailVerificationController::class, 'resend'])
        ->middleware(['cms.auth', 'throttle:cms-auth-verification-send'])
        ->name('verification.send'.$suffix);
};

Route::middleware(['web', 'cms.locale'])->group(static fn () => $cmsAuthRoutes(''));

Route::middleware(['web', 'cms.canonical-locale', 'cms.locale'])
    ->prefix('{locale}')
    ->whereIn('locale', explode('|', LanguageManager::routeLocalePattern()))
    ->group(static fn () => $cmsAuthRoutes('.localized'));
