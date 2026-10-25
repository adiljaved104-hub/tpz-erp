<?php

use App\Http\Controllers\Api\Mobile\V1\AppVersionController;
use App\Http\Controllers\Api\Mobile\V1\AuthController;
use App\Http\Controllers\Api\Mobile\V1\CaseController;
use App\Http\Controllers\Api\Mobile\V1\ChatController;
use App\Http\Controllers\Api\Mobile\V1\DashboardController;
use App\Http\Controllers\Api\Mobile\V1\DeviceController;
use App\Http\Controllers\Api\Mobile\V1\HrController;
use App\Http\Controllers\Api\Mobile\V1\InventoryLocationController;
use App\Http\Controllers\Api\Mobile\V1\InventoryReservationController;
use App\Http\Controllers\Api\Mobile\V1\InvoiceController;
use App\Http\Controllers\Api\Mobile\V1\NotificationController;
use App\Http\Controllers\Api\Mobile\V1\OrderController;
use App\Http\Controllers\Api\Mobile\V1\PasswordResetController;
use App\Http\Controllers\Api\Mobile\V1\ProductController;
use App\Http\Controllers\Api\Mobile\V1\PurchaseController;
use App\Http\Controllers\Api\Mobile\V1\PurchaseReceiptController;
use App\Http\Controllers\Api\Mobile\V1\QcDispatchController;
use App\Http\Controllers\Api\Mobile\V1\QcInspectionController;
use App\Http\Controllers\Api\Mobile\V1\QcRecordedStockIntakeController;
use App\Http\Controllers\Api\Mobile\V1\QcStockTransferController;
use App\Http\Controllers\Api\Mobile\V1\ReportController;
use App\Http\Controllers\Api\Mobile\V1\ResponsibilityController;
use App\Http\Controllers\Api\Mobile\V1\ReturnController;
use App\Http\Controllers\Api\Mobile\V1\ReturnEvidenceController;
use App\Http\Controllers\Api\Mobile\V1\SearchController;
use App\Http\Controllers\Api\Mobile\V1\StockRequestController;
use App\Http\Controllers\Api\Mobile\V1\StockTransferController;
use App\Http\Controllers\Api\Mobile\V1\SupplierController;
use App\Http\Controllers\Api\Mobile\V1\TaskController;
use App\Http\Controllers\Api\Mobile\V1\WarrantyController;
use App\Http\Controllers\Api\Mobile\V1\WorkspaceController;
use App\Http\Middleware\EnsureEligibleEmployee;
use App\Http\Middleware\EnsureTechnicianQcOnlyMobile;
use Illuminate\Support\Facades\Route;

Route::get('mobile/v1/app/version', AppVersionController::class)->middleware('throttle:60,1');

Route::prefix('mobile/v1/auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/mfa/verify', [AuthController::class, 'verifyMfa'])
        ->middleware('throttle:10,1');

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
    ->middleware(['auth:sanctum', EnsureEligibleEmployee::class, EnsureTechnicianQcOnlyMobile::class])
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class);
        Route::get('/workspace/modules', [WorkspaceController::class, 'modules']);
        Route::get('/workspace/manifest', [WorkspaceController::class, 'manifest']);
        Route::post('/devices', [DeviceController::class, 'register'])->middleware('throttle:mobile-device-register');
        Route::delete('/devices', [DeviceController::class, 'unregister']);

        Route::prefix('chat')->controller(ChatController::class)->group(function (): void {
            Route::get('/', 'index');
            Route::get('/options', 'options');
            Route::post('/', 'store')->middleware('throttle:mobile-chat-create');
            Route::get('/{conversation}', 'show')->whereNumber('conversation');
            Route::get('/{conversation}/messages', 'messages')->whereNumber('conversation');
            Route::post('/{conversation}/messages', 'send')->whereNumber('conversation')->middleware('throttle:mobile-chat-message');
            Route::post('/{conversation}/read', 'read')->whereNumber('conversation');
        });
        Route::prefix('workspace')->controller(WorkspaceController::class)->group(function (): void {
            Route::get('/qc', [QcDispatchController::class, 'home']);
            Route::get('/qc/inspections/options', [QcInspectionController::class, 'options']);
            Route::post('/qc/inspections', [QcInspectionController::class, 'start'])->middleware('throttle:30,1');
            Route::get('/qc/inspections', [QcInspectionController::class, 'index']);
            Route::get('/qc/recorded-renewed-stock', [QcRecordedStockIntakeController::class, 'index']);
            Route::post('/qc/recorded-renewed-stock/{intake}/start', [QcRecordedStockIntakeController::class, 'start'])->whereNumber('intake')->middleware('throttle:30,1');
            Route::get('/qc/inspections/{inspection}', [QcInspectionController::class, 'show'])->whereNumber('inspection');
            Route::post('/qc/inspections/{inspection}/begin', [QcInspectionController::class, 'begin'])->whereNumber('inspection')->middleware('throttle:60,1');
            Route::patch('/qc/inspections/{inspection}', [QcInspectionController::class, 'update'])->whereNumber('inspection')->middleware('throttle:60,1');
            Route::post('/qc/inspections/{inspection}/evidence', [QcInspectionController::class, 'evidence'])->whereNumber('inspection')->middleware('throttle:30,1');
            Route::get('/qc/return-evidence', [ReturnEvidenceController::class, 'index']);
            Route::get('/qc/return-evidence/dashboard', [ReturnEvidenceController::class, 'dashboard']);
            Route::get('/qc/return-evidence/files/{evidence}', [ReturnEvidenceController::class, 'original'])->whereNumber('evidence')->middleware('throttle:60,1');
            Route::get('/qc/return-evidence/{return}', [ReturnEvidenceController::class, 'show'])->whereNumber('return');
            Route::post('/qc/return-evidence/{return}/items/{item}', [ReturnEvidenceController::class, 'store'])->whereNumber(['return', 'item'])->middleware('throttle:10,1');
            Route::post('/qc/return-evidence/{return}/items/{item}/video-uploads', [ReturnEvidenceController::class, 'startVideo'])->whereNumber(['return', 'item'])->middleware('throttle:30,1,return-video-start');
            Route::get('/qc/return-evidence/video-uploads/{session}', [ReturnEvidenceController::class, 'videoStatus'])->whereUuid('session')->middleware('throttle:60,1,return-video-status');
            Route::post('/qc/return-evidence/video-uploads/{session}/chunks/{index}', [ReturnEvidenceController::class, 'uploadVideoChunk'])->whereUuid('session')->whereNumber('index')->middleware('throttle:120,1,return-video-chunks');
            Route::post('/qc/return-evidence/video-uploads/{session}/finalize', [ReturnEvidenceController::class, 'finalizeVideo'])->whereUuid('session')->middleware('throttle:10,1,return-video-finalize');
            Route::post('/qc/inspections/{inspection}/complete', [QcInspectionController::class, 'complete'])->whereNumber('inspection')->middleware('throttle:30,1');
            Route::get('/qc/pending', [QcDispatchController::class, 'index']);
            Route::get('/qc/open-order-demand', [QcDispatchController::class, 'openOrderDemand']);
            Route::get('/qc/dispatch', [QcDispatchController::class, 'index']);
            Route::get('/qc/orders/{order}', [QcDispatchController::class, 'show'])->whereNumber('order');
            Route::get('/qc/transfers', [QcStockTransferController::class, 'index']);
            Route::get('/qc/transfers/{transfer}', [QcStockTransferController::class, 'show'])->whereNumber('transfer');
            Route::post('/qc/transfers/{transfer}/verified-scan', [QcStockTransferController::class, 'verifiedScan'])->whereNumber('transfer')->middleware('throttle:60,1');
            Route::post('/qc/transfers/{transfer}/assignments/{assignment}/release', [QcStockTransferController::class, 'release'])->whereNumber(['transfer', 'assignment'])->middleware('throttle:30,1');
            Route::post('/qc/orders/{order}/scan', [QcDispatchController::class, 'scan'])->whereNumber('order')->middleware('throttle:60,1');
            Route::post('/qc/orders/{order}/verified-scan', [QcDispatchController::class, 'verifiedScan'])->whereNumber('order')->middleware('throttle:60,1');
            Route::post('/qc/orders/{order}/ship', [QcDispatchController::class, 'ship'])->whereNumber('order')->middleware('throttle:30,1');
            Route::post('/qc/bulk-ship', [QcDispatchController::class, 'bulkShip'])->middleware('throttle:10,1');
            Route::get('/search', SearchController::class);
            Route::get('/inventory', 'inventory');
            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product');
            Route::post('/products/{product}/update', [ProductController::class, 'update'])->whereNumber('product');
            Route::get('/hr', [HrController::class, 'home']);
            Route::get('/hr/options', [HrController::class, 'options']);
            Route::get('/hr/{section}', [HrController::class, 'index']);
            Route::get('/hr/{section}/{record}', [HrController::class, 'show'])->whereNumber('record');
            Route::post('/hr/notices', [HrController::class, 'publishNotice']);
            Route::post('/hr/announcements', [HrController::class, 'publishAnnouncement']);
            Route::post('/hr/warnings', [HrController::class, 'issueWarning']);
            Route::post('/hr/{section}/{record}/acknowledge', [HrController::class, 'acknowledge'])->whereNumber('record');
            Route::get('/purchases', [PurchaseController::class, 'index']);
            Route::get('/purchases/options', [PurchaseController::class, 'options']);
            Route::post('/purchases', [PurchaseController::class, 'store']);
            Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::put('/purchases/{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase');
            Route::post('/purchases/{purchase}/{action}', [PurchaseController::class, 'act'])->whereNumber('purchase');
            Route::get('/purchase-receipts', [PurchaseReceiptController::class, 'index']);
            Route::get('/purchase-receipts/{receipt}', [PurchaseReceiptController::class, 'show'])->whereNumber('receipt');
            Route::get('/suppliers', [SupplierController::class, 'index']);
            Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier');
            Route::get('/inventory-locations', [InventoryLocationController::class, 'index']);
            Route::get('/inventory-locations/{location}', [InventoryLocationController::class, 'show'])->whereNumber('location');
            Route::get('/stock-transfers', [StockTransferController::class, 'index']);
            Route::get('/stock-transfers/options', [StockTransferController::class, 'options']);
            Route::post('/stock-transfers', [StockTransferController::class, 'store']);
            Route::get('/stock-transfers/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer');
            Route::post('/stock-transfers/{transfer}/{action}', [StockTransferController::class, 'act'])->whereNumber('transfer');
            Route::get('/stock-requests', [StockRequestController::class, 'index']);
            Route::get('/stock-requests/options', [StockRequestController::class, 'options']);
            Route::post('/stock-requests', [StockRequestController::class, 'store']);
            Route::get('/stock-requests/{stockRequest}', [StockRequestController::class, 'show'])->whereNumber('stockRequest');
            Route::post('/stock-requests/{stockRequest}/{action}', [StockRequestController::class, 'act'])->whereNumber('stockRequest');
            Route::get('/reservations', [InventoryReservationController::class, 'index']);
            Route::get('/reservations/{reservation}', [InventoryReservationController::class, 'show'])->whereNumber('reservation');
            Route::post('/reservations/{reservation}/release', [InventoryReservationController::class, 'release'])->whereNumber('reservation');
            Route::get('/reports', [ReportController::class, 'index']);
            Route::get('/reports/{report}/export/{format}', [ReportController::class, 'export'])
                ->where('report', '[A-Za-z0-9._-]+')
                ->where('format', 'xlsx|csv|pdf');
            Route::get('/reports/{report}', [ReportController::class, 'show'])
                ->where('report', '[A-Za-z0-9._-]+');
            Route::get('/invoices', [InvoiceController::class, 'index']);
            Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');
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
            Route::post('/notifications/{notification}/acknowledge', [NotificationController::class, 'acknowledge']);
            Route::get('/returns', [ReturnController::class, 'index']);
            Route::get('/returns/options', [ReturnController::class, 'options']);
            Route::get('/returns/eligible-orders', [ReturnController::class, 'eligibleOrders']);
            Route::post('/returns', [ReturnController::class, 'store']);
            Route::get('/returns/{return}', [ReturnController::class, 'show'])->whereNumber('return');
            Route::post('/returns/{return}/{action}', [ReturnController::class, 'act'])->whereNumber('return');
            Route::get('/cases/{kind}', [CaseController::class, 'index'])->whereIn('kind', ['claims', 'complaints']);
            Route::get('/cases/{kind}/{record}', [CaseController::class, 'show'])->whereIn('kind', ['claims', 'complaints'])->whereNumber('record');
            Route::post('/cases/{kind}/{record}/{action}', [CaseController::class, 'act'])->whereIn('kind', ['claims', 'complaints'])->whereNumber('record');
            Route::get('/internal-repairs', [WarrantyController::class, 'internalRepairs']);
            Route::get('/warranty', [WarrantyController::class, 'index']);
            Route::get('/warranty/{warranty}', [WarrantyController::class, 'show'])->whereNumber('warranty');
            Route::post('/warranty/{warranty}/{action}', [WarrantyController::class, 'act'])->whereNumber('warranty');
            Route::get('/tasks', [TaskController::class, 'index']);
            Route::get('/tasks/options', [TaskController::class, 'options']);
            Route::post('/tasks', [TaskController::class, 'store']);
            Route::get('/tasks/{task}', [TaskController::class, 'show'])->whereNumber('task');
            Route::post('/tasks/{task}/{action}', [TaskController::class, 'act'])->whereNumber('task');
        });
    });
