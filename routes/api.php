<?php

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
use App\Http\Controllers\Api\PayPalReturnController;
use App\Http\Controllers\Auth\AdminPasswordResetLinkController;
use App\Http\Controllers\Auth\AdminNewPasswordController;

/*
|--------------------------------------------------------------------------
| Public Storefront Routes
|--------------------------------------------------------------------------
*/

// Auth
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
Route::post('/two-factor/resend', [AuthController::class, 'resendTwoFactor'])->middleware('throttle:two-factor-resend');
Route::get('/user', [AuthController::class, 'user']);
Route::post('/forgot-password', AdminPasswordResetLinkController::class)->middleware('throttle:password-reset');
Route::post('/reset-password', AdminNewPasswordController::class)->middleware('throttle:password-reset');

// Products
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Site config
Route::get('/site-config', [SiteConfigController::class, 'show']);

// Discounts
Route::get('/discounts', [DiscountController::class, 'index']);
Route::get('/discounts/{id}', [DiscountController::class, 'show']);
Route::post('/discounts/validate', [DiscountController::class, 'validate'])->middleware('throttle:discount-validate');

// Shipping
Route::get('/shipping/options', [ShippingSettingsController::class, 'options']);
Route::get('/shipping/zones', [ShippingSettingsController::class, 'zones']);
Route::post('/shipping/calculate', [ShippingSettingsController::class, 'calculate']);

// Tax
Route::post('/tax/calculate', [TaxSettingsController::class, 'calculate']);
Route::post('/tax/calculate-cart', [TaxSettingsController::class, 'calculateCart']);

/*
|--------------------------------------------------------------------------
| Checkout & Payment Routes
|--------------------------------------------------------------------------
*/

// Orders
Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:order-store');

// Payments
Route::post('/orders/{order}/pay', [PaymentController::class, 'pay'])->middleware('throttle:order-pay');
Route::post('/orders/{order}/stripe/intent', [PaymentController::class, 'stripeIntent'])->middleware('throttle:stripe-intent');
Route::get('/payments/{payment}', [PaymentController::class, 'show']);
Route::get('/payment-settings/methods', [PaymentSettingsController::class, 'methods']);

// PayPal redirects
Route::get('/paypal/return', [PayPalReturnController::class, 'return']);
Route::get('/paypal/cancel', [PayPalReturnController::class, 'cancel']);
Route::post('/paypal/capture', [PayPalReturnController::class, 'capture'])->middleware('throttle:paypal-capture');

// Webhooks
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->whereIn('provider', ['stripe', 'paypal', 'square']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum', 'session.lifetime'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Order management
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}', [OrderController::class, 'updateStatus']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Admin Routes
    |----------------------------------------------------------------------
    */

    Route::middleware('admin')->group(function () {
        // Products
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::patch('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        // Site config
        Route::patch('/site-config', [SiteConfigController::class, 'update']);
        Route::post('/site-config/media/{collection}', [SiteConfigController::class, 'uploadMedia']);
        Route::delete('/site-config/media/{collection}', [SiteConfigController::class, 'deleteMedia']);

        // Discounts
        Route::post('/discounts', [DiscountController::class, 'store']);
        Route::patch('/discounts/{id}', [DiscountController::class, 'update']);
        Route::delete('/discounts/{id}', [DiscountController::class, 'destroy']);

        // Shipping settings
        Route::get('/shipping-settings', [ShippingSettingsController::class, 'show']);
        Route::patch('/shipping-settings', [ShippingSettingsController::class, 'update']);

        // Tax settings
        Route::get('/tax-settings', [TaxSettingsController::class, 'show']);
        Route::patch('/tax-settings', [TaxSettingsController::class, 'update']);

        // Payment settings
        Route::get('/payment-settings', [PaymentSettingsController::class, 'show']);
        Route::patch('/payment-settings', [PaymentSettingsController::class, 'update']);
    });
});
