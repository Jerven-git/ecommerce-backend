<?php

use App\Http\Controllers\Api\V1\AdminAssistantController;
use App\Http\Controllers\Api\V1\SiteConfigController;
use App\Http\Controllers\Api\V1\SiteConfigDraftController;
use App\Http\Controllers\Api\V1\SiteConfigDraftReviewController;
use App\Http\Controllers\Api\V1\SiteConfigVersionController;
use App\Http\Controllers\Api\V1\WebsiteEditorMediaController;
use Illuminate\Support\Facades\Route;

require base_path('app/Modules/Identity/Routes/passwords.php');

Route::prefix('v1')->group(function (): void {
    require base_path('app/Modules/Identity/Routes/public.php');
    require base_path('app/Modules/ControlPlane/Routes/public.php');
    require base_path('app/Modules/Subscriptions/Routes/public.php');

    Route::middleware(['storefront', 'tenant'])->group(function (): void {
        require base_path('app/Modules/Catalog/Routes/storefront.php');
        require base_path('app/Modules/CommerceSettings/Routes/storefront.php');
        require base_path('app/Modules/Editorial/Routes/storefront.php');

        // SiteConfig and Website Editor remain outside this modularization track.
        Route::get('/site-config', [SiteConfigController::class, 'show']);

        require base_path('app/Modules/Engagement/Routes/storefront.php');
        require base_path('app/Modules/Shipments/Routes/storefront.php');
        require base_path('app/Modules/Orders/Routes/storefront.php');
        require base_path('app/Modules/Payments/Routes/storefront.php');
        require base_path('app/Modules/Backorders/Routes/storefront.php');
    });

    require base_path('app/Modules/Payments/Routes/webhooks.php');
    require base_path('app/Modules/Subscriptions/Routes/webhooks.php');

    Route::middleware(['auth:sanctum', 'session.lifetime', 'account.active', 'tenant'])->group(function (): void {
        require base_path('app/Modules/Identity/Routes/authenticated.php');
        require base_path('app/Modules/ControlPlane/Routes/authenticated.php');
        require base_path('app/Modules/Subscriptions/Routes/authenticated.php');

        Route::middleware('admin')->group(function (): void {
            require base_path('app/Modules/Orders/Routes/admin.php');
            require base_path('app/Modules/Shipments/Routes/admin.php');
            require base_path('app/Modules/Backorders/Routes/admin.php');
        });

        Route::middleware(['admin', 'subscribed'])->group(function (): void {
            // Dashboard analytics and Admin Assistant remain outside the domain extraction.
            Route::post('/admin-assistant', [AdminAssistantController::class, 'chat'])
                ->middleware('throttle:30,1');

            require base_path('app/Modules/Catalog/Routes/admin.php');
            require base_path('app/Modules/CommerceSettings/Routes/admin.php');
            require base_path('app/Modules/Engagement/Routes/admin.php');
            require base_path('app/Modules/Editorial/Routes/admin.php');
            require base_path('app/Modules/Media/Routes/admin.php');

            // SiteConfig and Website Editor routes retain their existing contract in place.
            Route::patch('/site-config', [SiteConfigController::class, 'update']);
            Route::get('/site-config/draft', [SiteConfigDraftController::class, 'show']);
            Route::put('/site-config/draft', [SiteConfigDraftController::class, 'store']);
            Route::delete('/site-config/draft', [SiteConfigDraftController::class, 'destroy']);
            Route::post('/site-config/draft/publish', [SiteConfigDraftController::class, 'publish']);
            Route::post('/site-config/draft/review/request', [SiteConfigDraftReviewController::class, 'requestReview']);
            Route::post('/site-config/draft/review/approve', [SiteConfigDraftReviewController::class, 'approve']);
            Route::post('/site-config/draft/review/request-changes', [SiteConfigDraftReviewController::class, 'requestChanges']);
            Route::post('/site-config/draft/schedule', [SiteConfigDraftReviewController::class, 'schedule']);
            Route::delete('/site-config/draft/schedule', [SiteConfigDraftReviewController::class, 'cancelSchedule']);
            Route::post('/site-config/draft/media/{collection}', [SiteConfigDraftController::class, 'uploadMedia']);
            Route::get('/site-config/media-library', [WebsiteEditorMediaController::class, 'index']);
            Route::patch('/site-config/media-library/{media}', [WebsiteEditorMediaController::class, 'update']);
            Route::delete('/site-config/media-library/{media}', [WebsiteEditorMediaController::class, 'destroy']);
            Route::post('/site-config/media-library/{media}/use/{slot}', [WebsiteEditorMediaController::class, 'reuse']);
            Route::get('/site-config/versions', [SiteConfigVersionController::class, 'index']);
            Route::get('/site-config/versions/{version}', [SiteConfigVersionController::class, 'show']);
            Route::post('/site-config/versions/{version}/restore', [SiteConfigVersionController::class, 'restore']);
            Route::delete('/site-config/versions/{version}', [SiteConfigVersionController::class, 'destroy']);
            Route::post('/site-config/media/{collection}', [SiteConfigController::class, 'uploadMedia']);
            Route::delete('/site-config/media/{collection}', [SiteConfigController::class, 'deleteMedia']);
            Route::post('/site-config/watch-shop/cards', [SiteConfigController::class, 'uploadWatchShopCard']);
            Route::delete('/site-config/watch-shop/cards/{cardId}', [SiteConfigController::class, 'deleteWatchShopCard']);
        });
    });
});
