<?php

use App\Http\Controllers\Api\V1\PostCategoryController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use Illuminate\Support\Facades\Route;

Route::get('/admin/posts', [PostController::class, 'adminIndex']);
Route::get('/admin/posts/{id}', [PostController::class, 'adminShow']);
Route::post('/admin/posts', [PostController::class, 'store']);
Route::patch('/admin/posts/{id}', [PostController::class, 'update']);
Route::delete('/admin/posts/{id}', [PostController::class, 'destroy']);
Route::post('/post-categories', [PostCategoryController::class, 'store']);
Route::post('/post-categories/reorder', [PostCategoryController::class, 'reorder']);
Route::patch('/post-categories/{id}', [PostCategoryController::class, 'update']);
Route::delete('/post-categories/{id}', [PostCategoryController::class, 'destroy']);
Route::delete('/post-categories/{id}/image', [PostCategoryController::class, 'deleteImage']);
Route::get('/admin/services', [ServiceController::class, 'adminIndex']);
Route::get('/admin/services/{id}', [ServiceController::class, 'adminShow']);
Route::post('/admin/services', [ServiceController::class, 'store']);
Route::post('/admin/services/reorder', [ServiceController::class, 'reorder']);
Route::patch('/admin/services/{id}', [ServiceController::class, 'update']);
Route::delete('/admin/services/{id}', [ServiceController::class, 'destroy']);
Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
Route::post('/service-categories/reorder', [ServiceCategoryController::class, 'reorder']);
Route::patch('/service-categories/{id}', [ServiceCategoryController::class, 'update']);
Route::delete('/service-categories/{id}', [ServiceCategoryController::class, 'destroy']);
Route::delete('/service-categories/{id}/image', [ServiceCategoryController::class, 'deleteImage']);
