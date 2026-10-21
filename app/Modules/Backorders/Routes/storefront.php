<?php

use App\Http\Controllers\Api\V1\BackorderController;
use Illuminate\Support\Facades\Route;

Route::get('/backorders/pay/{token}', [BackorderController::class, 'verifyToken'])->middleware('throttle:backorder-token');
Route::post('/backorders/pay/{token}', [BackorderController::class, 'payByToken'])->middleware('throttle:order-pay');
Route::post('/backorders/pay/{token}/confirm', [BackorderController::class, 'confirmWithoutPayment'])->middleware('throttle:order-pay');
