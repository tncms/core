<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendEmailVerificationController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendLoginController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendPasswordResetController;
use TheNguyen\CMS\Http\Controllers\Auth\FrontendRegisterController;

/*
|--------------------------------------------------------------------------
| Frontend Authentication routes (v1.0.0-beta.7.1.14)
|--------------------------------------------------------------------------
| Literal paths registered before the frontend {slug} catch-all so /login,
| /register, etc. resolve as auth pages. All routes run in the "web" group for
| session + CSRF. Framework-standard names (password.reset / verification.verify)
| are used so Laravel's built-in reset/verification notifications resolve with
| no extra wiring. Routes are intentionally unprefixed (no locale segment) for
| this foundation phase; localisation can be layered on later.
*/

Route::middleware('web')->group(function (): void {
    // Guest-only pages.
    Route::middleware('cms.guest')->group(function (): void {
        Route::get('/login', [FrontendLoginController::class, 'show'])->name('cms.auth.login');
        Route::post('/login', [FrontendLoginController::class, 'login'])->name('cms.auth.login.attempt');

        Route::get('/register', [FrontendRegisterController::class, 'show'])->name('cms.auth.register');
        Route::post('/register', [FrontendRegisterController::class, 'register'])->name('cms.auth.register.store');

        Route::get('/forgot-password', [FrontendPasswordResetController::class, 'showForgot'])->name('password.request');
        Route::post('/forgot-password', [FrontendPasswordResetController::class, 'sendResetLink'])->name('password.email');

        Route::get('/reset-password/{token}', [FrontendPasswordResetController::class, 'showReset'])->name('password.reset');
        Route::post('/reset-password', [FrontendPasswordResetController::class, 'reset'])->name('password.update');
    });

    // Session teardown.
    Route::post('/logout', [FrontendLoginController::class, 'logout'])
        ->middleware('cms.auth')
        ->name('cms.auth.logout');

    // Email verification.
    Route::get('/email/verify', [FrontendEmailVerificationController::class, 'notice'])
        ->middleware('cms.auth')
        ->name('cms.auth.verification.notice');

    Route::get('/email/verify/{id}/{hash}', [FrontendEmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [FrontendEmailVerificationController::class, 'resend'])
        ->middleware(['cms.auth', 'throttle:6,1'])
        ->name('verification.send');
});
