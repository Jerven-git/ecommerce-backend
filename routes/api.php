<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\SiteConfigController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\ShippingSettingsController;
use App\Http\Controllers\Api\V1\TaxSettingsController;
use App\Http\Controllers\Api\V1\PaymentSettingsController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Api\V1\PayPalReturnController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\BackorderController;
use App\Http\Controllers\Auth\AdminPasswordResetLinkController;
use App\Http\Controllers\Auth\AdminNewPasswordController;

/*
|--------------------------------------------------------------------------
| Shared Auth Routes (not versioned)
|--------------------------------------------------------------------------
*/
Route::post('/forgot-password', AdminPasswordResetLinkController::class)->middleware('throttle:password-reset');
Route::post('/reset-password', AdminNewPasswordController::class)->middleware('throttle:password-reset');

Route::prefix('v1')->group(function () {

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

    // Products
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);

    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);

    // Site config
    Route::get('/site-config', [SiteConfigController::class, 'show']);

    // Contact form
    Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact');

    // Shipment tracking (public)
    Route::get('/tracking/{trackingNumber}', [ShipmentController::class, 'track']);
    Route::get('/tracking/{trackingNumber}/barcode', [ShipmentController::class, 'barcode']);

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

    // Backorder payment (public - token-based)
    Route::get('/backorders/pay/{token}', [BackorderController::class, 'verifyToken']);
    Route::post('/backorders/pay/{token}', [BackorderController::class, 'payByToken'])->middleware('throttle:order-pay');

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum', 'session.lifetime'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        // Dashboard
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Order management
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show']);
        Route::patch('/orders/{id}', [OrderController::class, 'updateStatus']);
        Route::post('/orders/{id}/confirm-payment', [OrderController::class, 'confirmPayment']);
        Route::post('/orders/{id}/undo-payment', [OrderController::class, 'undoPayment']);
        Route::post('/orders/{id}/cancel-refund', [OrderController::class, 'cancelAndRefund']);
        Route::delete('/orders/{id}', [OrderController::class, 'destroy']);

        // Shipment management
        Route::post('/orders/{id}/ship', [ShipmentController::class, 'ship']);
        Route::patch('/shipments/{id}', [ShipmentController::class, 'update']);

        // Backorder management
        Route::get('/backorders', [BackorderController::class, 'index']);
        Route::get('/backorders/{id}', [BackorderController::class, 'show']);
        Route::post('/backorders/{id}/notify', [BackorderController::class, 'notify']);
        Route::post('/backorders/{id}/resend', [BackorderController::class, 'resend']);
        Route::post('/backorders/{id}/cancel', [BackorderController::class, 'cancel']);
        Route::get('/backorder-settings', [BackorderController::class, 'settings']);
        Route::patch('/backorder-settings', [BackorderController::class, 'updateSettings']);

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
            Route::post('/products/{id}/images', [ProductController::class, 'uploadImages']);
            Route::delete('/products/{id}/images/{mediaId}', [ProductController::class, 'deleteImage']);

            // Categories
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::patch('/categories/{id}', [CategoryController::class, 'update']);
            Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

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

});
