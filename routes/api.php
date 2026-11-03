<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ProvisionController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/ping', fn() => response()->json(['status' => 'ok', 'app' => 'dropship_store_api_for_ecom']));

// ==========================================
// STORE PROVISIONING (Used by ResellerBrain backend)
// ==========================================
Route::prefix('provision')->group(function () {
    Route::post('/store', [ProvisionController::class, 'provisionStore']);
    Route::post('/sync', [ProvisionController::class, 'syncStore']);
    Route::get('/stores', [ProvisionController::class, 'listStores']);
});

// ==========================================
// PUBLIC STOREFRONT ROUTES (Tenant-Scoped by Host Domain)
// ==========================================
Route::prefix('store')->middleware('resolve.store')->group(function () {
    Route::get('/info', [StorefrontController::class, 'getStoreInfo']);
    Route::get('/categories', [StorefrontController::class, 'getCategories']);
    Route::get('/products', [StorefrontController::class, 'getProducts']);
    Route::get('/products/{slug}', [StorefrontController::class, 'getProductDetails']);
    Route::post('/checkout', [StorefrontController::class, 'checkout']);
    Route::get('/orders/track/{orderNumber}', [StorefrontController::class, 'trackOrder']);
    Route::get('/order/{orderNumber}', [StorefrontController::class, 'trackOrder']);
});

// ==========================================
// STORE OWNER ADMIN ROUTES (Tenant-Scoped & Auth-Protected)
// ==========================================
Route::prefix('admin')->middleware('resolve.store')->group(function () {
    // Public within the store (Login)
    Route::post('/login', [AdminController::class, 'login']);

    // Authenticated admin routes
    Route::middleware('store.admin')->group(function () {
        Route::get('/check-auth', [AdminController::class, 'checkAuth']);
        Route::post('/logout', [AdminController::class, 'logout']);
        Route::post('/change-password', [AdminController::class, 'changePassword']);

        Route::get('/dashboard', [AdminController::class, 'getDashboard']);
        Route::get('/settings', [AdminController::class, 'getSettings']);
        Route::post('/settings', [AdminController::class, 'updateSettings']);
        
        // ResellerBrain Sync & Handshake
        Route::post('/resellerbrain/test-connection', [AdminController::class, 'testRbConnection']);
        Route::post('/resellerbrain/sync', [AdminController::class, 'triggerRbSync']);

        // Product Management
        Route::get('/products', [AdminController::class, 'getProducts']);
        Route::post('/products', [AdminController::class, 'createCustomProduct']);
        Route::put('/products/{id}', [AdminController::class, 'updateProduct']);

        // Order Management
        Route::get('/orders', [AdminController::class, 'getOrders']);
        Route::post('/orders/{id}/forward-to-rb', [AdminController::class, 'retryForwardRbOrder']);
        Route::post('/orders/{id}/status', [AdminController::class, 'updateOrderStatus']);
    });
});
