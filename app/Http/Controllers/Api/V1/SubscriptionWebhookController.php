<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Application\StripeWebhookProcessor;
use App\Modules\Subscriptions\Application\SubscriptionOperationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SubscriptionWebhookController extends Controller
{
    public function __construct(private readonly StripeWebhookProcessor $webhooks) {}

    public function handle(Request $request): Response
    {
        $secret = (string) config('payment.stripe.webhook_secret');
        if (! $secret) {
            return response('Stripe subscription webhook not configured', 200);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (\Throwable) {
            return response('Invalid signature', 400);
        }

        try {
            $this->webhooks->process($event->toArray());
        } catch (SubscriptionOperationException $exception) {
            return response($exception->getMessage(), $exception->status);
        } catch (Throwable) {
            return response('Webhook processing failed', 500);
        }

        return response('OK', 200);
    }
}
