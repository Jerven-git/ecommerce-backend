<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Application\WebhookReceiver;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(
        string $provider,
        Request $request,
        WebhookReceiver $webhooks,
        ?string $store = null,
    ) {
        $result = $webhooks->receive($provider, $request, $store);

        return response($result->message, $result->status);
    }
}
