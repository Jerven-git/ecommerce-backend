<?php

use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\SuperAdmin\ActivityLogController;
use App\Http\Controllers\Api\V1\SuperAdmin\ImpersonationController;
use App\Http\Controllers\Api\V1\SuperAdmin\OverviewController;
use App\Http\Controllers\Api\V1\SuperAdmin\StoreController;
use App\Http\Controllers\Api\V1\SuperAdmin\StoreDomainController;
use App\Http\Controllers\Api\V1\SuperAdmin\StoreReadinessController;
use Illuminate\Support\Facades\Route;

Route::middleware('super_admin')->prefix('super-admin')->group(function (): void {
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::get('/users/{user}', [AdminUserController::class, 'show']);
    Route::patch('/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);

    Route::get('/overview', OverviewController::class);
    Route::get('/stores', [StoreController::class, 'index']);
    Route::get('/store-options', [StoreController::class, 'options']);
    Route::get('/stores/domain-availability', [StoreDomainController::class, 'check']);
    Route::post('/stores/{store}/domain/verify', [StoreDomainController::class, 'verify']);
    Route::get('/stores/{store}', [StoreController::class, 'show']);
    Route::get('/stores/{store}/admins', [StoreController::class, 'admins']);
    Route::get('/stores/{store}/readiness', StoreReadinessController::class);
    Route::patch('/stores/{store}', [StoreController::class, 'update']);
    Route::delete('/stores/{store}', [StoreController::class, 'destroy']);
    Route::post('/stores/{store}/activate', [StoreController::class, 'activate']);
    Route::post('/stores/{store}/deactivate', [StoreController::class, 'deactivate']);
    Route::post('/stores/{store}/comp', [StoreController::class, 'comp']);
    Route::post('/stores/{store}/uncomp', [StoreController::class, 'uncomp']);
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start']);
});

Route::post('/super-admin/impersonate/leave', [ImpersonationController::class, 'leave']);
Route::get('/activity-log', [ActivityLogController::class, 'index'])->middleware('subscribed');
Route::get('/admin/users', [AdminUserController::class, 'index'])->middleware('super_admin');
Route::post('/admin/users', [AdminUserController::class, 'store'])->middleware('super_admin');
Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->middleware('super_admin');
Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->middleware('super_admin');
Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->middleware('subscribed');
