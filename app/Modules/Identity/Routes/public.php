<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\RegisterController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [RegisterController::class, 'register'])->middleware('throttle:register');
Route::post('/two-factor/verify', [AuthController::class, 'verifyTwoFactor'])->middleware('throttle:two-factor');
Route::post('/two-factor/resend', [AuthController::class, 'resendTwoFactor'])->middleware('throttle:two-factor-resend');
Route::get('/user', [AuthController::class, 'user']);
