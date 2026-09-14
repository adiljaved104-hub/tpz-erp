<?php

use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\CaseController;
use App\Http\Controllers\Api\Mobile\V1\ChatController;
use App\Http\Controllers\Api\Mobile\V1\DashboardController;
use App\Http\Controllers\Api\Mobile\V1\DeviceController;
use App\Http\Controllers\Api\Mobile\V1\HrController;
use App\Http\Controllers\Api\Mobile\V1\NotificationController;
use App\Http\Controllers\Api\Mobile\V1\OrderController;
use App\Http\Controllers\Api\Mobile\V1\PasswordResetController;
use App\Http\Controllers\Api\Mobile\V1\ProductController;
use App\Http\Controllers\Api\Mobile\V1\PurchaseController;
use App\Http\Controllers\Api\Mobile\V1\ResponsibilityController;
use App\Http\Controllers\Api\Mobile\V1\ReturnController;
use App\Http\Controllers\Api\Mobile\V1\TaskController;
use App\Http\Controllers\Api\Mobile\V1\WarrantyController;
use App\Http\Controllers\Api\Mobile\V1\WorkspaceController;
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

Route::prefix('mobile/v1')
    ->middleware(['auth:sanctum', EnsureEligibleEmployee::class])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/workspace/modules', [WorkspaceController::class, 'modules']);
        Route::post('/devices', [DeviceController::class, 'register'])->middleware('throttle:30,1');
        Route::delete('/devices', [DeviceController::class, 'unregister']);

        Route::prefix('chat')->controller(ChatController::class)->group(function (): void {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::post('/', 'store')->middleware('throttle:30,1');
            Route::get('/{conversation}', 'show')->whereNumber('conversation');
            Route::get('/{conversation}/messages', 'messages')->whereNumber('conversation');
            Route::post('/{conversation}/messages', 'send')->whereNumber('conversation')->middleware('throttle:60,1');
            Route::post('/{conversation}/read', 'read')->whereNumber('conversation');
        });
        Route::prefix('workspace')->controller(WorkspaceController::class)->group(function (): void {
            Route::get('/inventory', 'inventory');
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::post('/products/{product}/update', [ProductController::class, 'update'])->whereNumber('product');
            Route::get('/hr', [HrController::class, 'home']);
            Route::get('/hr/options', [HrController::class, 'options']);
            Route::get('/hr/{section}', [HrController::class, 'index']);
            Route::get('/hr/{section}/{record}', [HrController::class, 'show'])->whereNumber('record');
            Route::post('/hr/notices', [HrController::class, 'publishNotice']);
            Route::post('/hr/warnings', [HrController::class, 'issueWarning']);
            Route::post('/hr/{section}/{record}/acknowledge', [HrController::class, 'acknowledge'])->whereNumber('record');
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::get('/purchases/options', [PurchaseController::class, 'options']);
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase');
            Route::post('/purchases/{purchase}/{action}', [PurchaseController::class, 'act'])->whereNumber('purchase');
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/options', [OrderController::class, 'options']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
            Route::put('/orders/{order}', [OrderController::class, 'update'])->whereNumber('order');
            Route::post('/orders/{order}/{action}', [OrderController::class, 'act'])->whereNumber('order');
            Route::get('/responsibilities', [ResponsibilityController::class, 'index']);
            Route::get('/responsibilities/{responsibility}', [ResponsibilityController::class, 'show'])->whereNumber('responsibility');
            Route::post('/responsibilities/{responsibility}/{action}', [ResponsibilityController::class, 'act'])->whereNumber('responsibility');
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'read']);
            Route::get('/returns', [ReturnController::class, 'index']);
            Route::get('/returns/options', [ReturnController::class, 'options']);
            Route::get('/returns/eligible-orders', [ReturnController::class, 'eligibleOrders']);
            Route::post('/returns', [ReturnController::class, 'store']);
            Route::get('/returns/{return}', [ReturnController::class, 'show'])->whereNumber('return');
            Route::post('/returns/{return}/{action}', [ReturnController::class, 'act'])->whereNumber('return');
            Route::get('/cases/{kind}', [CaseController::class, 'index'])->whereIn('kind', ['claims', 'complaints']);
            Route::get('/cases/{kind}/{record}', [CaseController::class, 'show'])->whereIn('kind', ['claims', 'complaints'])->whereNumber('record');
            Route::get('/internal-repairs', [WarrantyController::class, 'internalRepairs']);
            Route::get('/warranty', [WarrantyController::class, 'index']);
            Route::get('/warranty/{warranty}', [WarrantyController::class, 'show'])->whereNumber('warranty');
            Route::post('/warranty/{warranty}/{action}', [WarrantyController::class, 'act'])->whereNumber('warranty');
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
            Route::post('/tasks/{task}/{action}', [TaskController::class, 'act'])->whereNumber('task');
        });
    });
