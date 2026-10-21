<?php

use App\Http\Controllers\Api\V1\BackorderController;
use Illuminate\Support\Facades\Route;

Route::get('/backorders', [BackorderController::class, 'index']);
Route::get('/backorders/{id}', [BackorderController::class, 'show']);
Route::post('/backorders/{id}/notify', [BackorderController::class, 'notify']);
Route::post('/backorders/{id}/resend', [BackorderController::class, 'resend']);
Route::post('/backorders/{id}/cancel', [BackorderController::class, 'cancel']);
Route::post('/backorders/{id}/mark-paid', [BackorderController::class, 'markAsPaid']);
Route::get('/backorder-settings', [BackorderController::class, 'settings']);
Route::patch('/backorder-settings', [BackorderController::class, 'updateSettings']);
