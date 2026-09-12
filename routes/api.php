<?php

use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\PasswordResetController;
use App\Http\Middleware\EnsureEligibleEmployee;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1/auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:mobile-login');

    Route::post('/password/forgot', [PasswordResetController::class, 'requestCode'])
        ->middleware('throttle:mobile-login');

    Route::post('/password/verify', [PasswordResetController::class, 'verifyCode'])
        ->middleware('throttle:10,1');

    Route::post('/password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', EnsureEligibleEmployee::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
