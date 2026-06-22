<?php

use App\Http\Controllers\Api\V1\AdminAssistantController;
use App\Http\Controllers\Api\V1\AdminGiftCardController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackorderController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CommissionRequestController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\GiftCardController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentSettingsController;
use App\Http\Controllers\Api\V1\PayPalReturnController;
use App\Http\Controllers\Api\V1\PostCategoryController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ShipmentController;
use App\Http\Controllers\Api\V1\ShippingSettingsController;
use App\Http\Controllers\Api\V1\SiteConfigController;
use App\Http\Controllers\Api\V1\SubscribeController;
use App\Http\Controllers\Api\V1\SuperAdmin\ActivityLogController as SuperAdminActivityLogController;
use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonationController as SuperAdminImpersonationController;
use App\Http\Controllers\Api\V1\SuperAdmin\StoreController as SuperAdminStoreController;
use App\Http\Controllers\Api\V1\TaxReportController;
use App\Http\Controllers\Api\V1\TaxRuleController;
use App\Http\Controllers\Api\V1\TaxSettingsController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Http\Controllers\Auth\AdminNewPasswordController;
use App\Http\Controllers\Auth\AdminPasswordResetLinkController;
use Illuminate\Support\Facades\Route;

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
    | Auth (host-agnostic — admin login works from any subdomain or apex)
    |--------------------------------------------------------------------------
    */

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
    Route::post('/two-factor/resend', [AuthController::class, 'resendTwoFactor'])->middleware('throttle:two-factor-resend');
    Route::get('/user', [AuthController::class, 'user']);

    /*
    |--------------------------------------------------------------------------
    | Public Storefront Routes (resolve store from Host header)
    |--------------------------------------------------------------------------
    |
    | `storefront` resolves the store from the Host header for public visitors.
    | `tenant` runs after it: for an authenticated admin it overrides CurrentStore
    | to *their* store, so admin tools that reuse these public read endpoints
    | (e.g. the products/categories lists) see their own store's data regardless
    | of which host the SPA is loaded from. It is a no-op for guests.
    |
    */

    Route::middleware(['storefront', 'tenant'])->group(function () {

        // Products
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{slug}', [ProductController::class, 'show']);

        // Categories
        Route::get('/categories', [CategoryController::class, 'index']);

        // Currencies (public storefront list)
        Route::get('/currencies', [CurrencyController::class, 'index']);

        // Blog (public)
        Route::get('/posts', [PostController::class, 'index']);
        Route::get('/posts/{slug}', [PostController::class, 'show']);
        Route::get('/post-categories', [PostCategoryController::class, 'index']);
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{slug}', [ServiceController::class, 'show']);
        Route::get('/service-categories', [ServiceCategoryController::class, 'index']);

        // Site config
        Route::get('/site-config', [SiteConfigController::class, 'show']);

        // Contact form
        Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact');

        // Gift cards (public)
        Route::get('/gift-card-denominations', [GiftCardController::class, 'denominations']);
        Route::post('/gift-cards/validate', [GiftCardController::class, 'validate'])->middleware('throttle:discount-validate');

        // Commission requests (public submit)
        Route::post('/commission-requests', [CommissionRequestController::class, 'store'])->middleware('throttle:contact');

        // Subscribe (welcome popup)
        Route::post('/subscribe', [SubscribeController::class, 'store'])->middleware('throttle:contact');

        // Shipment tracking (public, rate-limited)
        Route::get('/tracking/{trackingNumber}', [ShipmentController::class, 'track'])->middleware('throttle:tracking');
        Route::get('/tracking/{trackingNumber}/barcode', [ShipmentController::class, 'barcode'])->middleware('throttle:tracking');

        // Discounts (validate only — listing is admin-only)
        Route::post('/discounts/validate', [DiscountController::class, 'validate'])->middleware('throttle:discount-validate');

        // Shipping
        Route::get('/shipping/options', [ShippingSettingsController::class, 'options']);
        Route::get('/shipping/zones', [ShippingSettingsController::class, 'zones']);
        Route::post('/shipping/calculate', [ShippingSettingsController::class, 'calculate']);

        // Tax
        Route::post('/tax/calculate', [TaxSettingsController::class, 'calculate']);
        Route::post('/tax/calculate-cart', [TaxSettingsController::class, 'calculateCart']);
        Route::get('/tax/resolve', [TaxSettingsController::class, 'resolve']);

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
        Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('throttle:payment-show');
        Route::get('/payment-settings/methods', [PaymentSettingsController::class, 'methods']);

        // PayPal redirects
        Route::get('/paypal/return', [PayPalReturnController::class, 'return']);
        Route::get('/paypal/cancel', [PayPalReturnController::class, 'cancel']);
        Route::post('/paypal/capture', [PayPalReturnController::class, 'capture'])->middleware('throttle:paypal-capture');

        // Backorder payment (public - token-based, rate-limited)
        Route::get('/backorders/pay/{token}', [BackorderController::class, 'verifyToken'])->middleware('throttle:backorder-token');
        Route::post('/backorders/pay/{token}', [BackorderController::class, 'payByToken'])->middleware('throttle:order-pay');
        Route::post('/backorders/pay/{token}/confirm', [BackorderController::class, 'confirmWithoutPayment'])->middleware('throttle:order-pay');

    }); // end storefront-resolved group

    /*
    |--------------------------------------------------------------------------
    | Webhooks (host-agnostic — providers POST to a fixed URL)
    |--------------------------------------------------------------------------
    */

    // Per-store webhook URL (each store pastes its own into the provider dashboard).
    Route::post('/webhooks/{provider}/{store}', [WebhookController::class, 'handle'])
        ->whereIn('provider', ['stripe', 'paypal', 'square']);

    // Legacy single-URL webhook — resolves to the default store (config fallback).
    Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
        ->whereIn('provider', ['stripe', 'paypal', 'square']);

    /*
    |--------------------------------------------------------------------------
    | Authenticated Routes
    |--------------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum', 'session.lifetime', 'tenant'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);

        /*
        |----------------------------------------------------------------------
        | Super Admin Routes
        |----------------------------------------------------------------------
        */
        Route::middleware('super_admin')->prefix('super-admin')->group(function () {
            // Admin user management
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::post('/users', [AdminUserController::class, 'store']);
            Route::patch('/users/{user}', [AdminUserController::class, 'update']);
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

            // Stores
            Route::get('/stores', [SuperAdminStoreController::class, 'index']);
            Route::post('/stores', [SuperAdminStoreController::class, 'store']);
            Route::get('/stores/{store}', [SuperAdminStoreController::class, 'show']);
            Route::patch('/stores/{store}', [SuperAdminStoreController::class, 'update']);
            Route::delete('/stores/{store}', [SuperAdminStoreController::class, 'destroy']);
            Route::post('/stores/{store}/activate', [SuperAdminStoreController::class, 'activate']);
            Route::post('/stores/{store}/deactivate', [SuperAdminStoreController::class, 'deactivate']);

            // Start impersonation (super_admin only)
            Route::post('/users/{user}/impersonate', [SuperAdminImpersonationController::class, 'start']);
        });

        // Leave impersonation — hit by the impersonated user, so cannot be inside
        // the super_admin group.
        Route::post('/super-admin/impersonate/leave', [SuperAdminImpersonationController::class, 'leave']);

        // Activity log — visible to all admins; controller scopes by role.
        Route::get('/activity-log', [SuperAdminActivityLogController::class, 'index']);

        // Legacy admin-user routes (kept for one release; new clients should use /super-admin/users).
        Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('super_admin');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('super_admin');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('super_admin');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->middleware('super_admin');

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
        Route::post('/backorders/{id}/mark-paid', [BackorderController::class, 'markAsPaid']);
        Route::get('/backorder-settings', [BackorderController::class, 'settings']);
        Route::patch('/backorder-settings', [BackorderController::class, 'updateSettings']);

        /*
        |----------------------------------------------------------------------
        | Admin Routes
        |----------------------------------------------------------------------
        */

        Route::middleware('admin')->group(function () {
            // Admin help assistant (AI chatbot)
            Route::post('/admin-assistant', [AdminAssistantController::class, 'chat'])
                ->middleware('throttle:30,1');

            // Products
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{id}', [ProductController::class, 'update']);
            Route::patch('/products/{id}', [ProductController::class, 'update']);
            Route::delete('/products/{id}', [ProductController::class, 'destroy']);
            Route::post('/products/{id}/images', [ProductController::class, 'uploadImages']);
            Route::delete('/products/{id}/images/{mediaId}', [ProductController::class, 'deleteImage']);

            // Product Variants (admin)
            Route::get('/products/{id}/variants', [ProductVariantController::class, 'index']);
            Route::post('/products/{id}/variants/sync', [ProductVariantController::class, 'sync']);
            Route::post('/products/{id}/variants/{variantId}/image', [ProductVariantController::class, 'uploadVariantImage']);
            Route::delete('/products/{id}/variants/{variantId}/image', [ProductVariantController::class, 'deleteVariantImage']);

            // Categories
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::post('/categories/reorder', [CategoryController::class, 'reorder']);
            Route::patch('/categories/{id}', [CategoryController::class, 'update']);
            Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
            Route::delete('/categories/{id}/image', [CategoryController::class, 'deleteImage']);

            // Currencies (admin)
            Route::get('/admin/currencies', [CurrencyController::class, 'adminIndex']);
            Route::post('/currencies', [CurrencyController::class, 'store']);
            Route::patch('/currencies/{currency}', [CurrencyController::class, 'update']);
            Route::delete('/currencies/{currency}', [CurrencyController::class, 'destroy']);

            // Gift cards (admin)
            Route::get('/admin/gift-card-denominations', [AdminGiftCardController::class, 'denominationIndex']);
            Route::post('/gift-card-denominations', [AdminGiftCardController::class, 'denominationStore']);
            Route::patch('/gift-card-denominations/{denomination}', [AdminGiftCardController::class, 'denominationUpdate']);
            Route::delete('/gift-card-denominations/{denomination}', [AdminGiftCardController::class, 'denominationDestroy']);
            Route::get('/admin/gift-cards', [AdminGiftCardController::class, 'index']);
            Route::post('/admin/gift-cards', [AdminGiftCardController::class, 'store']);
            Route::patch('/admin/gift-cards/{giftCard}', [AdminGiftCardController::class, 'update']);

            // Commission requests (admin)
            Route::get('/commission-requests', [CommissionRequestController::class, 'index']);
            Route::get('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'show']);
            Route::patch('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'update']);
            Route::delete('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'destroy']);

            // Blog posts (admin)
            Route::get('/admin/posts', [PostController::class, 'adminIndex']);
            Route::get('/admin/posts/{id}', [PostController::class, 'adminShow']);
            Route::post('/admin/posts', [PostController::class, 'store']);
            Route::patch('/admin/posts/{id}', [PostController::class, 'update']);
            Route::delete('/admin/posts/{id}', [PostController::class, 'destroy']);

            // Blog categories (admin)
            Route::post('/post-categories', [PostCategoryController::class, 'store']);
            Route::post('/post-categories/reorder', [PostCategoryController::class, 'reorder']);
            Route::patch('/post-categories/{id}', [PostCategoryController::class, 'update']);
            Route::delete('/post-categories/{id}', [PostCategoryController::class, 'destroy']);
            Route::delete('/post-categories/{id}/image', [PostCategoryController::class, 'deleteImage']);

            // Services (admin)
            Route::get('/admin/services', [ServiceController::class, 'adminIndex']);
            Route::get('/admin/services/{id}', [ServiceController::class, 'adminShow']);
            Route::post('/admin/services', [ServiceController::class, 'store']);
            Route::post('/admin/services/reorder', [ServiceController::class, 'reorder']);
            Route::patch('/admin/services/{id}', [ServiceController::class, 'update']);
            Route::delete('/admin/services/{id}', [ServiceController::class, 'destroy']);

            // Service categories (admin)
            Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
            Route::post('/service-categories/reorder', [ServiceCategoryController::class, 'reorder']);
            Route::patch('/service-categories/{id}', [ServiceCategoryController::class, 'update']);
            Route::delete('/service-categories/{id}', [ServiceCategoryController::class, 'destroy']);
            Route::delete('/service-categories/{id}/image', [ServiceCategoryController::class, 'deleteImage']);

            // Media (alt_text edits)
            Route::patch('/media/{id}', [MediaController::class, 'update']);

            // Site config
            Route::patch('/site-config', [SiteConfigController::class, 'update']);
            Route::post('/site-config/media/{collection}', [SiteConfigController::class, 'uploadMedia']);
            Route::delete('/site-config/media/{collection}', [SiteConfigController::class, 'deleteMedia']);
            Route::post('/site-config/watch-shop/cards', [SiteConfigController::class, 'uploadWatchShopCard']);
            Route::delete('/site-config/watch-shop/cards/{cardId}', [SiteConfigController::class, 'deleteWatchShopCard']);

            // Discounts
            Route::get('/discounts', [DiscountController::class, 'index']);
            Route::get('/discounts/{id}', [DiscountController::class, 'show']);
            Route::post('/discounts', [DiscountController::class, 'store']);
            Route::patch('/discounts/{id}', [DiscountController::class, 'update']);
            Route::delete('/discounts/{id}', [DiscountController::class, 'destroy']);

            // Shipping settings
            Route::get('/shipping-settings', [ShippingSettingsController::class, 'show']);
            Route::patch('/shipping-settings', [ShippingSettingsController::class, 'update']);

            // Tax settings
            Route::get('/tax-settings', [TaxSettingsController::class, 'show']);
            Route::patch('/tax-settings', [TaxSettingsController::class, 'update']);

            // Tax rules (regional)
            Route::get('/tax-rules', [TaxRuleController::class, 'index']);
            Route::post('/tax-rules', [TaxRuleController::class, 'store']);
            Route::patch('/tax-rules/{id}', [TaxRuleController::class, 'update']);
            Route::delete('/tax-rules/{id}', [TaxRuleController::class, 'destroy']);
            Route::post('/tax-rules/sync', [TaxRuleController::class, 'sync']);

            // Tax report
            Route::get('/tax-report', [TaxReportController::class, 'index']);
            Route::get('/tax-report/export', [TaxReportController::class, 'export']);

            // Payment settings
            Route::get('/payment-settings', [PaymentSettingsController::class, 'show']);
            Route::patch('/payment-settings', [PaymentSettingsController::class, 'update']);
        });
    });

});
