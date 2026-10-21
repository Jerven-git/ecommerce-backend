<?php

use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/subscription', [SubscriptionController::class, 'show']);
Route::post('/subscription/checkout', [SubscriptionController::class, 'checkout']);
Route::post('/subscription/portal', [SubscriptionController::class, 'portal']);
