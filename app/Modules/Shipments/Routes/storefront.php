<?php

use App\Http\Controllers\Api\V1\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::get('/tracking/{trackingNumber}', [ShipmentController::class, 'track'])->middleware('throttle:tracking');
Route::get('/tracking/{trackingNumber}/barcode', [ShipmentController::class, 'barcode'])->middleware('throttle:tracking');
