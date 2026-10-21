<?php

use App\Http\Controllers\Api\V1\SubscriptionWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/subscription/stripe', [SubscriptionWebhookController::class, 'handle']);
