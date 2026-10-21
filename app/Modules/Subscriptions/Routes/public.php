<?php

use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/subscription/plans', [SubscriptionController::class, 'plans']);
