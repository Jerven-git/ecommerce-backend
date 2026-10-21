<?php

use App\Http\Controllers\Api\V1\CurrencyController;
use App\Http\Controllers\Api\V1\DiscountController;
use App\Http\Controllers\Api\V1\GiftCardController;
use App\Http\Controllers\Api\V1\ShippingSettingsController;
use App\Http\Controllers\Api\V1\TaxSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/currencies', [CurrencyController::class, 'index']);
Route::get('/gift-card-denominations', [GiftCardController::class, 'denominations']);
Route::post('/gift-cards/validate', [GiftCardController::class, 'validate'])->middleware('throttle:discount-validate');
Route::post('/discounts/validate', [DiscountController::class, 'validate'])->middleware('throttle:discount-validate');
Route::get('/shipping/options', [ShippingSettingsController::class, 'options']);
Route::get('/shipping/zones', [ShippingSettingsController::class, 'zones']);
Route::post('/shipping/calculate', [ShippingSettingsController::class, 'calculate']);
Route::post('/tax/calculate', [TaxSettingsController::class, 'calculate']);
Route::post('/tax/calculate-cart', [TaxSettingsController::class, 'calculateCart']);
Route::get('/tax/resolve', [TaxSettingsController::class, 'resolve']);
