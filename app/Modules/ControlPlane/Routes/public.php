<?php

use App\Http\Controllers\Api\V1\CanonicalHostController;
use App\Http\Controllers\Api\V1\TlsCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/tls-check', TlsCheckController::class);
Route::get('/canonical-host', CanonicalHostController::class);
