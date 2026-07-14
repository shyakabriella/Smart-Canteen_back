<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\FoodCategoryController;
use App\Http\Controllers\API\FoodItemController;
use App\Http\Controllers\API\CanteenTableController;
use App\Http\Controllers\API\InventoryStockController;
use App\Http\Controllers\API\StockMovementController;
use App\Http\Controllers\API\WalletTopUpController;
use App\Http\Controllers\API\WalletTransactionController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\OrderItemController;
use App\Http\Controllers\API\OrderQrCodeController;
use App\Http\Controllers\API\QrScanLogController;
use App\Http\Controllers\API\PickupConfirmationController;
use App\Http\Controllers\API\SupplierController;
use App\Http\Controllers\API\LowStockAlertController;
use App\Http\Controllers\API\PurchaseRequestController;
use App\Http\Controllers\API\SalesReportController;
use App\Http\Controllers\API\InventoryReportController;
use App\Http\Controllers\API\ActivityLogController;
use App\Http\Controllers\API\SystemSettingController;
use App\Http\Controllers\API\TableActionEventController;

/*
|--------------------------------------------------------------------------
| Public Authentication Routes
|--------------------------------------------------------------------------
*/

Route::controller(RegisterController::class)->group(function () {
    Route::post('register', 'register');
    Route::post('login', 'login');
});

/*
|--------------------------------------------------------------------------
| Public System Settings
|--------------------------------------------------------------------------
*/

Route::get(
    'system-settings/public',
    [SystemSettingController::class, 'publicSettings']
);

/*
|--------------------------------------------------------------------------
| Public Canteen Table QR Route
|--------------------------------------------------------------------------
|
| This endpoint is opened when a customer scans a table QR code.
| Authentication is not required.
|
*/

Route::get(
    'canteen-tables/public/{qrToken}',
    [CanteenTableController::class, 'publicShow']
)->whereUuid('qrToken');


/*
|--------------------------------------------------------------------------
| Public Table Action Routes
|--------------------------------------------------------------------------
|
| These endpoints are public because a customer uses them after scanning
| the unique QR code assigned to a canteen table.
|
| GET  /api/table-action-events/public
| POST /api/canteen-tables/public/{qrToken}/actions
|
*/

Route::get(
    'table-action-events/public',
    [TableActionEventController::class, 'publicIndex']
);

Route::post(
    'canteen-tables/public/{qrToken}/actions',
    [TableActionEventController::class, 'storePublic']
)
    ->whereUuid('qrToken')
    ->middleware('throttle:60,1');

/*
|--------------------------------------------------------------------------
| Protected API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Authentication and User Routes
    |--------------------------------------------------------------------------
    */

    Route::post(
        'logout',
        [RegisterController::class, 'logout']
    );

    Route::get(
        'profile',
        [RegisterController::class, 'profile']
    );

    /*
     * Used by wallet transactions and other administrative forms
     * to select users instead of entering user IDs manually.
     */
    Route::get(
        'users',
        [RegisterController::class, 'users']
    );

    /*
    |--------------------------------------------------------------------------
    | Food Category Routes
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'food-categories',
        FoodCategoryController::class
    );

    Route::post(
        'food-categories/{id}/restore',
        [FoodCategoryController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Food Item Routes
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'food-items',
        FoodItemController::class
    );

    Route::post(
        'food-items/{id}/restore',
        [FoodItemController::class, 'restore']
    )->whereNumber('id');

    Route::patch(
        'food-items/{foodItem}/availability',
        [FoodItemController::class, 'updateAvailability']
    )->whereNumber('foodItem');

    /*
    |--------------------------------------------------------------------------
    | Canteen Table Routes
    |--------------------------------------------------------------------------
    |
    | Every canteen table has a unique QR token.
    | The public QR endpoint is declared outside the auth middleware.
    |
    */

    Route::get(
        'canteen-tables/summary',
        [CanteenTableController::class, 'summary']
    );

    Route::post(
        'canteen-tables/{canteenTable}/regenerate-qr',
        [CanteenTableController::class, 'regenerateQr']
    )->whereNumber('canteenTable');

    Route::post(
        'canteen-tables/{id}/restore',
        [CanteenTableController::class, 'restore']
    )->whereNumber('id');

    Route::apiResource(
        'canteen-tables',
        CanteenTableController::class
    );

    /*
    |--------------------------------------------------------------------------
    | Inventory Stock Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'inventory-stocks/low-stock',
        [InventoryStockController::class, 'lowStock']
    );

    Route::apiResource(
        'inventory-stocks',
        InventoryStockController::class
    );

    Route::post(
        'inventory-stocks/{id}/restore',
        [InventoryStockController::class, 'restore']
    )->whereNumber('id');

    Route::patch(
        'inventory-stocks/{inventoryStock}/add-stock',
        [InventoryStockController::class, 'addStock']
    )->whereNumber('inventoryStock');

    Route::patch(
        'inventory-stocks/{inventoryStock}/reduce-stock',
        [InventoryStockController::class, 'reduceStock']
    )->whereNumber('inventoryStock');

    /*
    |--------------------------------------------------------------------------
    | Stock Movement Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'stock-movements/summary',
        [StockMovementController::class, 'summary']
    );

    Route::apiResource(
        'stock-movements',
        StockMovementController::class
    );

    Route::post(
        'stock-movements/{id}/restore',
        [StockMovementController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Wallet Top-Up Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'wallet-top-ups/summary',
        [WalletTopUpController::class, 'summary']
    );

    Route::apiResource(
        'wallet-top-ups',
        WalletTopUpController::class
    );

    Route::post(
        'wallet-top-ups/{walletTopUp}/approve',
        [WalletTopUpController::class, 'approve']
    )->whereNumber('walletTopUp');

    Route::post(
        'wallet-top-ups/{walletTopUp}/reject',
        [WalletTopUpController::class, 'reject']
    )->whereNumber('walletTopUp');

    Route::post(
        'wallet-top-ups/{walletTopUp}/cancel',
        [WalletTopUpController::class, 'cancel']
    )->whereNumber('walletTopUp');

    Route::post(
        'wallet-top-ups/{id}/restore',
        [WalletTopUpController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Wallet Transaction Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'wallet-transactions/summary',
        [WalletTransactionController::class, 'summary']
    );

    Route::apiResource(
        'wallet-transactions',
        WalletTransactionController::class
    );

    Route::post(
        'wallet-transactions/{id}/restore',
        [WalletTransactionController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Order Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'orders/summary',
        [OrderController::class, 'summary']
    );

    Route::apiResource(
        'orders',
        OrderController::class
    );

    Route::post(
        'orders/{order}/preparing',
        [OrderController::class, 'markPreparing']
    )->whereNumber('order');

    Route::post(
        'orders/{order}/ready',
        [OrderController::class, 'markReady']
    )->whereNumber('order');

    Route::post(
        'orders/{order}/complete',
        [OrderController::class, 'complete']
    )->whereNumber('order');

    Route::post(
        'orders/{order}/cancel',
        [OrderController::class, 'cancel']
    )->whereNumber('order');

    Route::post(
        'orders/{id}/restore',
        [OrderController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Order Item Routes
    |--------------------------------------------------------------------------
    */

    Route::apiResource(
        'order-items',
        OrderItemController::class
    )->except([
        'store',
    ]);

    Route::post(
        'order-items/{id}/restore',
        [OrderItemController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Order QR Code Routes
    |--------------------------------------------------------------------------
    */

    Route::post(
        'order-qr-codes/verify',
        [OrderQrCodeController::class, 'verify']
    );

    Route::apiResource(
        'order-qr-codes',
        OrderQrCodeController::class
    );

    Route::post(
        'order-qr-codes/{orderQrCode}/mark-used',
        [OrderQrCodeController::class, 'markUsed']
    )->whereNumber('orderQrCode');

    Route::post(
        'order-qr-codes/{orderQrCode}/regenerate',
        [OrderQrCodeController::class, 'regenerate']
    )->whereNumber('orderQrCode');

    Route::post(
        'order-qr-codes/{orderQrCode}/cancel',
        [OrderQrCodeController::class, 'cancel']
    )->whereNumber('orderQrCode');

    Route::post(
        'order-qr-codes/{id}/restore',
        [OrderQrCodeController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | QR Scan Log Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'qr-scan-logs/summary',
        [QrScanLogController::class, 'summary']
    );

    Route::apiResource(
        'qr-scan-logs',
        QrScanLogController::class
    )->only([
        'index',
        'show',
        'destroy',
    ]);

    Route::post(
        'qr-scan-logs/{id}/restore',
        [QrScanLogController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Pickup Confirmation Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'pickup-confirmations/summary',
        [PickupConfirmationController::class, 'summary']
    );

    Route::apiResource(
        'pickup-confirmations',
        PickupConfirmationController::class
    )->only([
        'index',
        'store',
        'show',
        'destroy',
    ]);

    Route::post(
        'pickup-confirmations/{pickupConfirmation}/cancel',
        [PickupConfirmationController::class, 'cancel']
    )->whereNumber('pickupConfirmation');

    Route::post(
        'pickup-confirmations/{id}/restore',
        [PickupConfirmationController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Supplier Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'suppliers/summary',
        [SupplierController::class, 'summary']
    );

    Route::apiResource(
        'suppliers',
        SupplierController::class
    );

    Route::post(
        'suppliers/{id}/restore',
        [SupplierController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Low Stock Alert Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'low-stock-alerts/summary',
        [LowStockAlertController::class, 'summary']
    );

    Route::post(
        'low-stock-alerts/generate',
        [LowStockAlertController::class, 'generate']
    );

    Route::apiResource(
        'low-stock-alerts',
        LowStockAlertController::class
    );

    Route::post(
        'low-stock-alerts/{lowStockAlert}/resolve',
        [LowStockAlertController::class, 'resolve']
    )->whereNumber('lowStockAlert');

    Route::post(
        'low-stock-alerts/{lowStockAlert}/dismiss',
        [LowStockAlertController::class, 'dismiss']
    )->whereNumber('lowStockAlert');

    Route::post(
        'low-stock-alerts/{id}/restore',
        [LowStockAlertController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Purchase Request Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'purchase-requests/summary',
        [PurchaseRequestController::class, 'summary']
    );

    Route::apiResource(
        'purchase-requests',
        PurchaseRequestController::class
    );

    Route::post(
        'purchase-requests/{purchaseRequest}/approve',
        [PurchaseRequestController::class, 'approve']
    )->whereNumber('purchaseRequest');

    Route::post(
        'purchase-requests/{purchaseRequest}/reject',
        [PurchaseRequestController::class, 'reject']
    )->whereNumber('purchaseRequest');

    Route::post(
        'purchase-requests/{purchaseRequest}/mark-ordered',
        [PurchaseRequestController::class, 'markOrdered']
    )->whereNumber('purchaseRequest');

    Route::post(
        'purchase-requests/{purchaseRequest}/receive',
        [PurchaseRequestController::class, 'receive']
    )->whereNumber('purchaseRequest');

    Route::post(
        'purchase-requests/{purchaseRequest}/cancel',
        [PurchaseRequestController::class, 'cancel']
    )->whereNumber('purchaseRequest');

    Route::post(
        'purchase-requests/{id}/restore',
        [PurchaseRequestController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Sales Report Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'sales-reports/summary',
        [SalesReportController::class, 'summary']
    );

    Route::post(
        'sales-reports/generate',
        [SalesReportController::class, 'generate']
    );

    Route::apiResource(
        'sales-reports',
        SalesReportController::class
    );

    Route::post(
        'sales-reports/{salesReport}/regenerate',
        [SalesReportController::class, 'regenerate']
    )->whereNumber('salesReport');

    Route::post(
        'sales-reports/{salesReport}/finalize',
        [SalesReportController::class, 'finalize']
    )->whereNumber('salesReport');

    Route::post(
        'sales-reports/{id}/restore',
        [SalesReportController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Inventory Report Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'inventory-reports/summary',
        [InventoryReportController::class, 'summary']
    );

    Route::post(
        'inventory-reports/generate',
        [InventoryReportController::class, 'generate']
    );

    Route::apiResource(
        'inventory-reports',
        InventoryReportController::class
    );

    Route::post(
        'inventory-reports/{inventoryReport}/regenerate',
        [InventoryReportController::class, 'regenerate']
    )->whereNumber('inventoryReport');

    Route::post(
        'inventory-reports/{inventoryReport}/finalize',
        [InventoryReportController::class, 'finalize']
    )->whereNumber('inventoryReport');

    Route::post(
        'inventory-reports/{id}/restore',
        [InventoryReportController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | Activity Log Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'activity-logs/summary',
        [ActivityLogController::class, 'summary']
    );

    Route::apiResource(
        'activity-logs',
        ActivityLogController::class
    )->only([
        'index',
        'store',
        'show',
        'destroy',
    ]);

    Route::post(
        'activity-logs/{id}/restore',
        [ActivityLogController::class, 'restore']
    )->whereNumber('id');

    /*
    |--------------------------------------------------------------------------
    | System Setting Routes
    |--------------------------------------------------------------------------
    */

    Route::get(
        'system-settings/summary',
        [SystemSettingController::class, 'summary']
    );

    Route::post(
        'system-settings/seed-defaults',
        [SystemSettingController::class, 'seedDefaults']
    );

    Route::post(
        'system-settings/bulk-update',
        [SystemSettingController::class, 'bulkUpdate']
    );

    Route::get(
        'system-settings/key/{settingKey}',
        [SystemSettingController::class, 'getByKey']
    );

    Route::patch(
        'system-settings/key/{settingKey}',
        [SystemSettingController::class, 'updateByKey']
    );

    Route::get(
        'system-settings',
        [SystemSettingController::class, 'index']
    );

    Route::post(
        'system-settings',
        [SystemSettingController::class, 'store']
    );

    Route::get(
        'system-settings/{systemSetting}',
        [SystemSettingController::class, 'show']
    )->whereNumber('systemSetting');

    Route::put(
        'system-settings/{systemSetting}',
        [SystemSettingController::class, 'update']
    )->whereNumber('systemSetting');

    Route::patch(
        'system-settings/{systemSetting}',
        [SystemSettingController::class, 'update']
    )->whereNumber('systemSetting');

    Route::delete(
        'system-settings/{systemSetting}',
        [SystemSettingController::class, 'destroy']
    )->whereNumber('systemSetting');

    Route::post(
        'system-settings/{id}/restore',
        [SystemSettingController::class, 'restore']
    )->whereNumber('id');
});