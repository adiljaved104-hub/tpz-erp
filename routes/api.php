<?php

use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Middleware\EnsureEligibleEmployee;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1/auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', EnsureEligibleEmployee::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});
