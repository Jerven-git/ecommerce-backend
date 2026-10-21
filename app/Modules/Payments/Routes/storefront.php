<?php

use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentSettingsController;
use App\Http\Controllers\Api\V1\PayPalReturnController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{order}/pay', [PaymentController::class, 'pay'])->middleware('throttle:order-pay');
Route::post('/orders/{order}/stripe/intent', [PaymentController::class, 'stripeIntent'])->middleware('throttle:stripe-intent');
Route::get('/payments/{payment}', [PaymentController::class, 'show'])->middleware('throttle:payment-show');
Route::get('/payment-settings/methods', [PaymentSettingsController::class, 'methods']);
Route::get('/paypal/return', [PayPalReturnController::class, 'return']);
Route::get('/paypal/cancel', [PayPalReturnController::class, 'cancel']);
Route::post('/paypal/capture', [PayPalReturnController::class, 'capture'])->middleware('throttle:paypal-capture');
