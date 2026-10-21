<?php

use App\Http\Controllers\Api\V1\ShipmentController;
use Illuminate\Support\Facades\Route;

Route::post('/orders/{id}/ship', [ShipmentController::class, 'ship']);
Route::patch('/shipments/{id}', [ShipmentController::class, 'update']);
