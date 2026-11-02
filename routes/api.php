<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Health check
Route::get('/ping', fn() => response()->json(['status' => 'ok', 'app' => 'dropship_store_api_for_ecom']));

// ==========================================
// PUBLIC STOREFRONT ROUTES
// ==========================================
Route::prefix('store')->group(function () {
    Route::get('/info', [StorefrontController::class, 'getStoreInfo']);
    Route::get('/categories', [StorefrontController::class, 'getCategories']);
    Route::get('/products', [StorefrontController::class, 'getProducts']);
    Route::get('/products/{slug}', [StorefrontController::class, 'getProductDetails']);
    Route::post('/checkout', [StorefrontController::class, 'checkout']);
    Route::get('/orders/track/{orderNumber}', [StorefrontController::class, 'trackOrder']);
    Route::get('/order/{orderNumber}', [StorefrontController::class, 'trackOrder']);
});

// ==========================================
// STORE OWNER ADMIN ROUTES
// ==========================================
Route::prefix('admin')->group(function () {
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
