<?php

use App\Http\Controllers\Api\V1\CommissionRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/commission-requests', [CommissionRequestController::class, 'index']);
Route::get('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'show']);
Route::patch('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'update']);
Route::delete('/commission-requests/{commissionRequest}', [CommissionRequestController::class, 'destroy']);
