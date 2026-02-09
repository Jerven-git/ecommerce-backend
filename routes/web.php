<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PayPalReturnController;

Route::get('/paypal/return', [PayPalReturnController::class, 'return'])->name('paypal.return');
Route::get('/paypal/cancel', [PayPalReturnController::class, 'cancel'])->name('paypal.cancel');
