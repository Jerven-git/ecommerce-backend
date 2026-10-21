<?php

use App\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/orders', [OrderController::class, 'index']);
Route::get('/orders/{id}', [OrderController::class, 'show']);
Route::patch('/orders/{id}', [OrderController::class, 'updateStatus']);
Route::post('/orders/{id}/confirm-payment', [OrderController::class, 'confirmPayment']);
Route::post('/orders/{id}/undo-payment', [OrderController::class, 'undoPayment']);
Route::post('/orders/{id}/cancel-refund', [OrderController::class, 'cancelAndRefund']);
Route::delete('/orders/{id}', [OrderController::class, 'destroy']);
