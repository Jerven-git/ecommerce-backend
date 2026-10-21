<?php

use App\Http\Controllers\Api\V1\CommissionRequestController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\SubscribeController;
use Illuminate\Support\Facades\Route;

Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact');
Route::post('/commission-requests', [CommissionRequestController::class, 'store'])->middleware('throttle:contact');
Route::post('/subscribe', [SubscribeController::class, 'store'])->middleware('throttle:contact');
