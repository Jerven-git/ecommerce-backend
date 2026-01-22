<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SiteConfigController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\ShippingSettingsController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/discounts/validate', [DiscountController::class, 'validate']);
Route::post('/shipping/calculate', [ShippingSettingsController::class, 'calculate']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/site-config', [SiteConfigController::class, 'show']);


// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Order routes (authenticated users)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::patch('/orders/{id}', [OrderController::class, 'updateStatus']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);

    // Admin routes (requires is_admin = true)
    Route::middleware('admin')->group(function () {
        // Product management
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::patch('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        // Site config management
        Route::put('/site-config', [SiteConfigController::class, 'update']);
        Route::patch('/site-config', [SiteConfigController::class, 'update']);

        // Discount management
        Route::post('/discounts', [DiscountController::class, 'store']);
        Route::get('/discounts', [DiscountController::class, 'index']);
        Route::get('/discounts/{id}', [DiscountController::class, 'show']);
        Route::patch('/discounts/{id}', [DiscountController::class, 'update']);
        Route::delete('/discounts/{id}', [DiscountController::class, 'destroy']);

        // Shipping settings management
        Route::get('/shipping-settings', [ShippingSettingsController::class, 'show']);
        Route::patch('/shipping-settings', [ShippingSettingsController::class, 'update']);
    });
});