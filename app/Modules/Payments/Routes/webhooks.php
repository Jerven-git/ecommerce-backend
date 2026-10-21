<?php

use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/{provider}/{store}', [WebhookController::class, 'handle'])
    ->whereIn('provider', ['stripe', 'paypal', 'square']);
Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
    ->whereIn('provider', ['stripe', 'paypal', 'square']);
