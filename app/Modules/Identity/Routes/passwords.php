<?php

use App\Http\Controllers\Auth\AdminNewPasswordController;
use App\Http\Controllers\Auth\AdminPasswordResetLinkController;
use Illuminate\Support\Facades\Route;

Route::post('/forgot-password', AdminPasswordResetLinkController::class)->middleware('throttle:password-reset');
Route::post('/reset-password', AdminNewPasswordController::class)->middleware('throttle:password-reset');
