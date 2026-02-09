<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SiteConfigController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\ShippingSettingsController;
use App\Http\Controllers\Api\TaxSettingsController;
use App\Http\Controllers\Api\PaymentSettingsController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/discounts/validate', [DiscountController::class, 'validate']);

Route::post('/tax/calculate', [TaxSettingsController::class, 'calculate']);
Route::post('/tax/calculate-cart', [TaxSettingsController::class, 'calculateCart']);

Route::post('/orders', [OrderController::class, 'store']);
Route::post('/shipping/calculate', [ShippingSettingsController::class, 'calculate']);

Route::get('/shipping/options', [ShippingSettingsController::class, 'options']);
Route::get('/shipping/zones', [ShippingSettingsController::class, 'zones']);

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::get('/site-config', [SiteConfigController::class, 'show']);

Route::post('/orders/{order}/pay', [PaymentController::class, 'pay']);
Route::post('/orders/{order}/stripe/intent', [PaymentController::class, 'stripeIntent']);

// webhooks (Stripe, PayPal, Square)
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->whereIn('provider', ['stripe', 'paypal', 'square']);

Route::get('/payment-settings/methods', [PaymentSettingsController::class, 'methods']);
Route::get('/payments/{payment}', [PaymentController::class, 'show']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Order routes (authenticated users)
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
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

        // Tax settings management
        Route::get('/tax-settings', [TaxSettingsController::class, 'show']);
        Route::patch('/tax-settings', [TaxSettingsController::class, 'update']);

        // Payment settings management
        Route::get('/payment-settings', [PaymentSettingsController::class, 'show']);
        Route::patch('/payment-settings', [PaymentSettingsController::class, 'update']);
    });
});