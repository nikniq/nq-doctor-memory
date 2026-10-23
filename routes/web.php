<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountDataController;
use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameSessionController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\ScoreController;
use Illuminate\Support\Facades\Route;

Route::get('/', GameController::class)->name('game');
Route::get('/leaderboard', LeaderboardController::class)->name('leaderboard');
Route::view('/privacy', 'privacy')->name('privacy');

Route::post('/games', [GameSessionController::class, 'store'])
    ->middleware('throttle:30,1,games')
    ->name('games.store');
Route::post('/games/{game}/finish', [GameSessionController::class, 'finish'])
    ->middleware('throttle:30,1,games')
    ->name('games.finish');

Route::get('/scores', [ScoreController::class, 'index'])->name('scores.index');
Route::post('/scores', [ScoreController::class, 'store'])
    ->middleware('throttle:10,1,scores')
    ->name('scores.store');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:auth-email');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:auth-email')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');

    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google');
    Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});

// Works signed out too, so the link can be opened on any device.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1,verify'])
    ->name('verification.verify');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification-send')
        ->name('verification.send');

    // Taking your data, or removing it, does not need a verified email.
    Route::get('/account/export', [AccountDataController::class, 'export'])
        ->middleware('throttle:3,10,export')
        ->name('account.export');
    Route::delete('/account', [AccountDataController::class, 'destroy'])
        ->middleware('throttle:5,1,delete-account')
        ->name('account.destroy');
    Route::post('/account/delete-link', [AccountDataController::class, 'sendDeleteLink'])
        ->middleware('throttle:verification-send')
        ->name('account.delete-link');
    Route::get('/account/delete/{user}', [AccountDataController::class, 'confirm'])
        ->middleware('signed')
        ->name('account.destroy.confirm');
    Route::post('/account/delete/{user}', [AccountDataController::class, 'destroySigned'])
        ->middleware(['signed', 'throttle:5,1,delete-account'])
        ->name('account.destroy.signed');

    Route::middleware('verified')->group(function () {
        Route::get('/account', AccountController::class)->name('account');
        Route::get('/account/settings', [AccountSettingsController::class, 'edit'])->name('account.settings');
        Route::patch('/account/name', [AccountSettingsController::class, 'updateName'])->name('account.name');
        Route::patch('/account/privacy', [AccountSettingsController::class, 'updatePrivacy'])->name('account.privacy');
    });
});
