<?php

use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use Illuminate\Support\Facades\Route;

Route::post('/products', [ProductController::class, 'store']);
Route::put('/products/{id}', [ProductController::class, 'update']);
Route::patch('/products/{id}', [ProductController::class, 'update']);
Route::delete('/products/{id}', [ProductController::class, 'destroy']);
Route::post('/products/{id}/images', [ProductController::class, 'uploadImages']);
Route::delete('/products/{id}/images/{mediaId}', [ProductController::class, 'deleteImage']);
Route::get('/products/{id}/variants', [ProductVariantController::class, 'index']);
Route::post('/products/{id}/variants/sync', [ProductVariantController::class, 'sync']);
Route::post('/products/{id}/variants/{variantId}/image', [ProductVariantController::class, 'uploadVariantImage']);
Route::delete('/products/{id}/variants/{variantId}/image', [ProductVariantController::class, 'deleteVariantImage']);
Route::post('/categories', [CategoryController::class, 'store']);
Route::post('/categories/reorder', [CategoryController::class, 'reorder']);
Route::patch('/categories/{id}', [CategoryController::class, 'update']);
Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
Route::delete('/categories/{id}/image', [CategoryController::class, 'deleteImage']);
